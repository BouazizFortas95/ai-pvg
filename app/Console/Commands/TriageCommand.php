<?php

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Services\Automation\Clustering\StoryClusteringService;
use App\Services\Automation\Greenlight\AutoGreenlightService;
use App\Services\Automation\Greenlight\GreenlightResult;
use App\Services\Automation\Scoring\NewsScoringService;
use App\Services\Automation\Triage\TriageGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TriageCommand extends Command
{
    protected $signature = 'ai-pvg:triage {--run : Execute triage scoring and clustering on un-scored news} {--cluster : Group scored news into story clusters} {--dry-run : Run without writing state if desired}';

    protected $description = 'Run autonomous news scoring, semantic clustering, and auto-greenlight triage.';

    public function handle(
        TriageGate $gate,
        NewsScoringService $scoring,
        StoryClusteringService $clustering,
        AutoGreenlightService $greenlight,
    ): int {
        // Triage is deterministic and local. Blocking HTTP turns any accidental
        // provider or crawler call into a loud failure instead of a side effect.
        Http::preventStrayRequests();

        if (! $gate->enabled()) {
            $this->error(
                'Triage is disabled. Enable automation.triage.enabled and the ['
                .$gate->flagKey().'] feature flag first.',
            );

            return self::FAILURE;
        }

        $runScoring = (bool) $this->option('run') || ! $this->option('cluster');
        $runClustering = (bool) $this->option('cluster');
        $commandDryRun = (bool) $this->option('dry-run');
        $configuredDryRun = (bool) config('automation.dry_run', true);
        $originalDryRun = config('automation.dry_run');

        $this->info('AI-PVG triage');
        $this->line('Triage gate: enabled ('.$gate->flagKey().')');
        $this->line('AUTOMATION_DRY_RUN: '.($configuredDryRun ? 'on' : 'off'));
        $this->line('Network: blocked (deterministic local scoring only)');

        if ($commandDryRun) {
            $this->warn('Command dry-run: on; every database write will be rolled back.');
        }

        $baseTransactionLevel = DB::transactionLevel();
        $summary = $this->emptySummary($runScoring, $runClustering);

        try {
            DB::beginTransaction();

            // Command-level dry-run is stricter than the environment switch:
            // force planned greenlight output and roll the whole run back below.
            if ($commandDryRun) {
                config(['automation.dry_run' => true]);
            }

            $summary = $this->triage($runScoring, $runClustering, $scoring, $clustering, $greenlight);

            if ($commandDryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            while (DB::transactionLevel() > $baseTransactionLevel) {
                DB::rollBack();
            }

            $this->error('Triage failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            config(['automation.dry_run' => $originalDryRun]);
        }

        $this->renderSummary($summary);

        if ($commandDryRun) {
            $this->warn('Dry run complete: transaction rolled back; no triage state was persisted.');
        } else {
            $this->info('Triage complete.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     scoring_requested: bool,
     *     clustering_requested: bool,
     *     scored: list<array<int, int|string>>,
     *     clusters: list<array<int, int|string>>,
     *     greenlights: list<array<int, int|string>>
     * }
     */
    private function triage(
        bool $runScoring,
        bool $runClustering,
        NewsScoringService $scoring,
        StoryClusteringService $clustering,
        AutoGreenlightService $greenlight,
    ): array {
        $summary = $this->emptySummary($runScoring, $runClustering);

        if ($runScoring) {
            $news = News::query()
                ->with('source')
                ->where('status', NewsStatus::Ready->value)
                ->whereDoesntHave('newsScore')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get();

            $scores = $scoring->scoreMany($news);

            foreach ($scores as $newsId => $score) {
                $article = $news->first(
                    fn (News $candidate): bool => (int) $candidate->getKey() === (int) $newsId,
                );

                if ($article === null) {
                    continue;
                }

                $summary['scored'][] = [
                    (int) $newsId,
                    (string) ($article->source?->name ?? $article->source_name ?? 'unknown'),
                    Str::limit((string) $article->title, 56),
                    $score->band->value,
                    number_format((float) $score->composite_score, 2),
                ];
            }
        }

        if ($runClustering) {
            foreach ($clustering->cluster() as $cluster) {
                $topScore = $cluster->maxCompositeScore();

                $summary['clusters'][] = [
                    (int) $cluster->getKey(),
                    Str::limit((string) $cluster->canonical_title, 52),
                    implode(', ', $cluster->newsIds()),
                    (int) $cluster->source_count,
                    number_format((float) $cluster->confidence, 2),
                    number_format($topScore, 2),
                ];

                $result = $greenlight->evaluateCluster($cluster);

                $summary['greenlights'][] = [
                    (int) $cluster->getKey(),
                    $result->action,
                    $result->band->value,
                    number_format($result->score, 2),
                    $this->greenlightOutcome($result),
                ];
            }
        }

        return $summary;
    }

    /**
     * @return array{
     *     scoring_requested: bool,
     *     clustering_requested: bool,
     *     scored: list<array<int, int|string>>,
     *     clusters: list<array<int, int|string>>,
     *     greenlights: list<array<int, int|string>>
     * }
     */
    private function emptySummary(bool $runScoring, bool $runClustering): array
    {
        return [
            'scoring_requested' => $runScoring,
            'clustering_requested' => $runClustering,
            'scored' => [],
            'clusters' => [],
            'greenlights' => [],
        ];
    }

    /**
     * @param  array{
     *     scoring_requested: bool,
     *     clustering_requested: bool,
     *     scored: list<array<int, int|string>>,
     *     clusters: list<array<int, int|string>>,
     *     greenlights: list<array<int, int|string>>
     * }  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->newLine();

        if ($summary['scoring_requested']) {
            $this->info('Scored articles');

            if ($summary['scored'] === []) {
                $this->line('No unscored ready articles found.');
            } else {
                $this->table(
                    ['ID', 'Source', 'Title', 'Band', 'Composite'],
                    $summary['scored'],
                );
                $this->line('Scored: '.count($summary['scored']).' article(s).');
            }
        } else {
            $this->line('Scoring skipped (pass --run to score unscored ready news).');
        }

        $this->newLine();

        if (! $summary['clustering_requested']) {
            $this->line('Clustering and auto-greenlight skipped (pass --cluster to run them).');

            return;
        }

        $this->info('Story clusters');

        if ($summary['clusters'] === []) {
            $this->line('No 3-5 source clusters were formed for the configured window.');
        } else {
            $this->table(
                ['Cluster', 'Canonical title', 'Members', 'Sources', 'Confidence', 'Top score'],
                $summary['clusters'],
            );
        }

        $this->newLine();
        $this->info('Auto-greenlight');

        if ($summary['greenlights'] === []) {
            $this->line('No clusters available for auto-greenlight.');

            return;
        }

        $this->table(
            ['Cluster', 'Action', 'Band', 'Score', 'Outcome'],
            $summary['greenlights'],
        );

        $actions = array_count_values(array_map(
            fn (array $row): string => (string) $row[1],
            $summary['greenlights'],
        ));
        $labels = [];

        foreach ($actions as $action => $count) {
            $labels[] = $action.'='.$count;
        }

        $this->line('Greenlight actions: '.implode(', ', $labels).'.');
    }

    private function greenlightOutcome(GreenlightResult $result): string
    {
        if ($result->isPlanned()) {
            $duration = $result->plannedScript['estimated_duration'] ?? null;
            $suffix = is_numeric($duration) ? ', '.$duration.'s' : '';

            return 'planned script (dry-run'.$suffix.')';
        }

        if ($result->script !== null) {
            return 'script #'.$result->script->getKey();
        }

        if ($result->gate !== null) {
            return 'approval gate #'.$result->gate->getKey();
        }

        if ($result->run !== null) {
            return 'automation run #'.$result->run->getKey();
        }

        return $result->message !== '' ? $result->message : 'no action';
    }
}
