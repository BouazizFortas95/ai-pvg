<?php

namespace App\Console\Commands;

use App\Services\Automation\Greenlight\GreenlightResult;
use App\Services\Automation\Triage\TriageGate;
use App\Services\Automation\Triage\TriageReport;
use App\Services\Automation\Triage\TriageRunner;
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
        TriageRunner $runner,
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

            $report = $runner->run($runScoring, $runClustering);
            $summary = $this->summarize($report, $runScoring, $runClustering);

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
     *     greenlights: list<array<int, int|string>>,
     *     cluster_diagnostics: array<string, mixed>
     * }
     */
    private function summarize(TriageReport $report, bool $runScoring, bool $runClustering): array
    {
        $summary = $this->emptySummary($runScoring, $runClustering);

        foreach ($report->scores as $newsId => $score) {
            $article = $score->news;

            if ($article === null) {
                continue;
            }

            $article->loadMissing('source');

            $summary['scored'][] = [
                (int) $newsId,
                (string) ($article->source?->name ?? $article->source_name ?? 'unknown'),
                Str::limit((string) $article->title, 56),
                $score->band->value,
                number_format((float) $score->composite_score, 2),
            ];
        }

        if ($runClustering) {
            foreach ($report->clusters as $cluster) {
                $topScore = $cluster->maxCompositeScore();
                $clusterId = (int) $cluster->getKey();

                $summary['clusters'][] = [
                    $clusterId,
                    Str::limit((string) $cluster->canonical_title, 52),
                    implode(', ', $cluster->newsIds()),
                    (int) $cluster->source_count,
                    number_format((float) $cluster->confidence, 2),
                    number_format($topScore, 2),
                ];

                $result = $report->greenlights[$clusterId] ?? null;

                if ($result === null) {
                    continue;
                }

                $summary['greenlights'][] = [
                    $clusterId,
                    $result->action,
                    $result->band->value,
                    number_format($result->score, 2),
                    $this->greenlightOutcome($result),
                ];
            }

            $summary['cluster_diagnostics'] = $report->clusterDiagnostics;
        }

        return $summary;
    }

    /**
     * @return array{
     *     scoring_requested: bool,
     *     clustering_requested: bool,
     *     scored: list<array<int, int|string>>,
     *     clusters: list<array<int, int|string>>,
     *     greenlights: list<array<int, int|string>>,
     *     cluster_diagnostics: array<string, mixed>
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
            'cluster_diagnostics' => [],
        ];
    }

    /**
     * @param  array{
     *     scoring_requested: bool,
     *     clustering_requested: bool,
     *     scored: list<array<int, int|string>>,
     *     clusters: list<array<int, int|string>>,
     *     greenlights: list<array<int, int|string>>,
     *     cluster_diagnostics: array<string, mixed>
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
            $this->renderClusteringDiagnostics($summary['cluster_diagnostics']);
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

    /**
     * @param  array<string, mixed>  $diagnostics
     */
    private function renderClusteringDiagnostics(array $diagnostics): void
    {
        if ($diagnostics === []) {
            return;
        }

        $candidates = (int) ($diagnostics['candidate_count'] ?? 0);
        $threshold = number_format((float) ($diagnostics['threshold'] ?? 0), 2);
        $windowHours = (int) ($diagnostics['window_hours'] ?? 0);
        $qualifyingPairs = (int) ($diagnostics['qualifying_pairs'] ?? 0);

        $this->newLine();
        $this->line('Clustering diagnostics');
        $this->line(
            "Candidates: {$candidates} recent article(s) in {$windowHours}h; "
            ."threshold {$threshold}; {$qualifyingPairs} pair(s) at or above threshold.",
        );

        $nearest = $diagnostics['nearest_pair'] ?? null;

        if (is_array($nearest)) {
            $this->line(
                'Closest pair: '.number_format((float) ($nearest['similarity'] ?? 0), 2)
                .' — "'.($nearest['left_title'] ?? 'unknown').'" ('.($nearest['left_source'] ?? 'unknown').')'
                .' <> "'.($nearest['right_title'] ?? 'unknown').'" ('.($nearest['right_source'] ?? 'unknown').').',
            );
        }

        if ($candidates < 3) {
            $this->line('At least 3 candidate articles are required to form a cluster.');

            return;
        }

        if ($qualifyingPairs === 0) {
            $this->line('No pair reached the threshold, so triage did not group unrelated articles to force a cluster.');

            return;
        }

        if ((int) ($diagnostics['valid_component_count'] ?? 0) === 0) {
            $this->line('Similar pairs were found, but no component contained 3-5 distinct sources.');
        }
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
