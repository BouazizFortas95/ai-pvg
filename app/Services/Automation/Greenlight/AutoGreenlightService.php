<?php

namespace App\Services\Automation\Greenlight;

use App\Enums\ApprovalGateType;
use App\Enums\AutomationTrigger;
use App\Enums\TriageBand;
use App\Enums\VideoScriptStatus;
use App\Models\AutomationRun;
use App\Models\News;
use App\Models\NewsScore;
use App\Models\StoryCluster;
use App\Models\VideoScript;
use App\Services\Automation\ApprovalService;
use App\Services\Automation\AutomationOrchestrator;
use App\Services\Automation\DryRunContext;
use App\Services\Automation\Triage\TriageGate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutoGreenlightService
{
    public function __construct(
        private readonly TriageGate $gate,
        private readonly AutomationOrchestrator $orchestrator,
        private readonly ApprovalService $approvals,
        private readonly DryRunContext $dryRun,
    ) {}

    public function evaluateCluster(StoryCluster $cluster, ?DateTimeInterface $asOf = null): GreenlightResult
    {
        $this->gate->assertEnabled();
        $cluster->loadMissing('news.newsScore');

        /** @var NewsScore|null $score */
        $score = $cluster->news
            ->map(fn (News $news): ?NewsScore => $news->newsScore)
            ->filter()
            ->sortByDesc(fn (NewsScore $score): float => (float) $score->composite_score)
            ->first();

        if ($score === null) {
            return new GreenlightResult(
                band: TriageBand::BelowThreshold,
                action: GreenlightResult::ACTION_NONE,
                score: 0.0,
                message: 'No member score exists yet; run news scoring first.',
            );
        }

        return $this->evaluate($cluster, $score, $asOf);
    }

    public function evaluate(StoryCluster $cluster, NewsScore $score, ?DateTimeInterface $asOf = null): GreenlightResult
    {
        $this->gate->assertEnabled();

        if (! $cluster->news()->whereKey((int) $score->news_id)->exists()) {
            return new GreenlightResult(
                band: TriageBand::BelowThreshold,
                action: GreenlightResult::ACTION_NONE,
                score: (float) $score->composite_score,
                message: 'The supplied score does not belong to this cluster.',
            );
        }

        $composite = (float) $score->composite_score;
        $thresholds = $this->thresholds();
        $band = TriageBand::fromScore(
            $composite,
            $thresholds['auto_greenlight_min'],
            $thresholds['review_band_min'],
        );

        return match ($band) {
            TriageBand::AutoGreenlight => $this->greenlight($cluster, $score, $band),
            TriageBand::Review => $this->openReviewGate($cluster, $score, $band),
            TriageBand::BelowThreshold => new GreenlightResult(
                band: $band,
                action: GreenlightResult::ACTION_NONE,
                score: $composite,
                message: 'Score is below the review band; no downstream action taken.',
            ),
        };
    }

    /**
     * Build the deterministic draft payload without persisting a VideoScript.
     * This is also the dry-run planned output.
     *
     * @return array<string, mixed>
     */
    public function buildDraftPayload(StoryCluster $cluster, NewsScore $score): array
    {
        $cluster->loadMissing(['news.newsScore']);
        $targetDuration = max(10, (int) config('automation.triage.target_duration', 45));
        $language = (string) config('automation.triage.language', 'en');
        $factSheet = $cluster->fact_sheet ?? [];
        $claims = array_values(array_filter(
            is_array($factSheet['claims'] ?? null) ? $factSheet['claims'] : [],
            fn (mixed $claim): bool => is_array($claim)
                && isset($claim['claim'], $claim['source_news_ids'])
                && is_array($claim['source_news_ids'])
                && $claim['source_news_ids'] !== [],
        ));
        $claims = array_slice($claims, 0, 3);
        $primary = $cluster->primaryNews();
        $primaryId = (int) ($primary?->getKey() ?? $cluster->news()->value('news_id'));
        $newsIds = $cluster->news->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        if ($claims === []) {
            $claims[] = [
                'claim' => (string) ($cluster->canonical_summary ?: $cluster->canonical_title),
                'source_news_ids' => [$primaryId],
            ];
        }

        $introDuration = 5;
        $outroDuration = 5;
        $available = max(count($claims), $targetDuration - $introDuration - $outroDuration);
        $base = intdiv($available, count($claims));
        $remainder = $available % count($claims);
        $scenes = [];
        $order = 1;

        $scenes[] = [
            'order' => $order++,
            'type' => 'intro',
            'duration' => $introDuration,
            'headline' => Str::limit((string) $cluster->canonical_title, 80, ''),
            'description' => 'Source-attributed introduction to the clustered story.',
            'narration' => 'Here is what multiple sources are reporting about '.$cluster->canonical_title.'.',
            'visual_keywords' => $this->visualKeywords($cluster),
            'source_news_ids' => [$primaryId],
        ];

        foreach ($claims as $index => $claim) {
            $duration = $base + ($index < $remainder ? 1 : 0);
            $sourceIds = array_values(array_map('intval', $claim['source_news_ids']));

            $scenes[] = [
                'order' => $order++,
                'type' => 'segment',
                'duration' => $duration,
                'headline' => Str::limit((string) $claim['claim'], 80, ''),
                'description' => 'Grounded claim sourced from news ids: '.implode(', ', $sourceIds).'.',
                'narration' => (string) $claim['claim'],
                'visual_keywords' => $this->visualKeywords($cluster),
                'source_news_ids' => $sourceIds,
            ];
        }

        $scenes[] = [
            'order' => $order,
            'type' => 'conclusion',
            'duration' => $outroDuration,
            'headline' => 'Why this story matters',
            'description' => 'Close with the verified cluster context.',
            'narration' => 'This story is developing. Review the source attribution before publishing.',
            'visual_keywords' => $this->visualKeywords($cluster),
            'source_news_ids' => $newsIds,
        ];

        return [
            'title' => (string) $cluster->canonical_title,
            'language' => $language,
            'target_duration' => $targetDuration,
            'estimated_duration' => array_sum(array_column($scenes, 'duration')),
            'narration' => implode(' ', array_column($scenes, 'narration')),
            'scenes' => $scenes,
            'status' => VideoScriptStatus::Draft->value,
            'ai_provider' => 'deterministic-local',
            'ai_model' => 'g02-draft-v1',
            'story_cluster_id' => (int) $cluster->getKey(),
            'score' => (float) $score->composite_score,
            'source_news_ids' => $newsIds,
            'source_count' => (int) $cluster->source_count,
        ];
    }

    private function greenlight(StoryCluster $cluster, NewsScore $score, TriageBand $band): GreenlightResult
    {
        $run = $this->orchestrator->createRun(
            pipeline: 'triage.auto_greenlight',
            subject: $cluster,
            trigger: AutomationTrigger::AutoGreenlight,
            dryRun: $this->dryRun->isDryRun(),
            activeWindow: 'cluster-'.$cluster->getKey(),
            meta: [
                'cluster_id' => (int) $cluster->getKey(),
                'news_ids' => $cluster->newsIds(),
                'score' => (float) $score->composite_score,
                'band' => $band->value,
                'source_count' => (int) $cluster->source_count,
            ],
        );

        $meta = $run->meta ?? [];
        $existingScriptId = isset($meta['video_script_id']) ? (int) $meta['video_script_id'] : null;

        if ($existingScriptId !== null) {
            $existing = VideoScript::query()->find($existingScriptId);

            if ($existing !== null) {
                return new GreenlightResult(
                    band: $band,
                    action: GreenlightResult::ACTION_AUTO_GREENLIGHT,
                    score: (float) $score->composite_score,
                    run: $run,
                    script: $existing,
                    message: 'Existing auto-greenlight script returned; no duplicate was created.',
                );
            }
        }

        if ($run->dry_run || $this->dryRun->isDryRun($run)) {
            $planned = $meta['planned_script'] ?? $this->buildDraftPayload($cluster, $score);
            $meta['planned_script'] = $planned;
            $meta['planned_at'] = $planned['planned_at'] ?? CarbonImmutable::now()->toIso8601String();
            $run->forceFill(['meta' => $meta])->save();

            if (! $run->isTerminal()) {
                $run = $this->orchestrator->completeRun($run);
            }

            return new GreenlightResult(
                band: $band,
                action: GreenlightResult::ACTION_AUTO_GREENLIGHT,
                score: (float) $score->composite_score,
                run: $run,
                plannedScript: $planned,
                message: 'Dry-run: script creation is planned but no production VideoScript was created.',
            );
        }

        $script = DB::transaction(function () use ($run, $cluster, $score): VideoScript {
            $locked = AutomationRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $lockedMeta = $locked->meta ?? [];
            $existingId = isset($lockedMeta['video_script_id']) ? (int) $lockedMeta['video_script_id'] : null;

            if ($existingId !== null) {
                $existing = VideoScript::query()->find($existingId);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $payload = $this->buildDraftPayload($cluster, $score);
            $script = VideoScript::create([
                'news_id' => (int) $cluster->primaryNews()?->getKey(),
                'title' => $payload['title'],
                'language' => $payload['language'],
                'target_duration' => $payload['target_duration'],
                'estimated_duration' => $payload['estimated_duration'],
                'narration' => $payload['narration'],
                'scenes' => $payload['scenes'],
                'status' => VideoScriptStatus::Draft,
                'ai_provider' => $payload['ai_provider'],
                'ai_model' => $payload['ai_model'],
            ]);

            $lockedMeta['video_script_id'] = (int) $script->getKey();
            $lockedMeta['script_created_at'] = CarbonImmutable::now()->toIso8601String();
            $locked->forceFill(['meta' => $lockedMeta])->save();

            return $script;
        });

        $run->refresh();

        if (! $run->isTerminal()) {
            $run = $this->orchestrator->completeRun($run);
        }

        return new GreenlightResult(
            band: $band,
            action: GreenlightResult::ACTION_AUTO_GREENLIGHT,
            score: (float) $score->composite_score,
            run: $run,
            script: $script,
            message: 'Draft VideoScript created by auto-greenlight.',
        );
    }

    private function openReviewGate(StoryCluster $cluster, NewsScore $score, TriageBand $band): GreenlightResult
    {
        $gate = $this->approvals->request(
            subject: $cluster,
            type: ApprovalGateType::AutoGreenlight,
            meta: [
                'cluster_id' => (int) $cluster->getKey(),
                'news_ids' => $cluster->newsIds(),
                'score' => (float) $score->composite_score,
                'band' => $band->value,
                'source_count' => (int) $cluster->source_count,
                'dry_run' => $this->dryRun->isDryRun(),
                'review_band_min' => $this->thresholds()['review_band_min'],
                'auto_greenlight_min' => $this->thresholds()['auto_greenlight_min'],
            ],
        );

        return new GreenlightResult(
            band: $band,
            action: GreenlightResult::ACTION_REVIEW_GATE,
            score: (float) $score->composite_score,
            gate: $gate,
            message: 'Score is in the review band; an idempotent approval gate is open.',
        );
    }

    /**
     * @return array{auto_greenlight_min: float, review_band_min: float}
     */
    private function thresholds(): array
    {
        $auto = (float) config(
            'automation.news_scoring.auto_greenlight_min',
            config('automation.news_scoring.min_auto_greenlight', 85),
        );
        // Keep the documented bands even if an environment value is unsafe.
        $auto = min(100.0, max(85.0, $auto));
        $review = (float) config('automation.news_scoring.review_band_min', 60);
        $review = min(84.0, max(60.0, $review));
        $review = min($auto, $review);

        return [
            'auto_greenlight_min' => $auto,
            'review_band_min' => $review,
        ];
    }

    /**
     * @return list<string>
     */
    private function visualKeywords(StoryCluster $cluster): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $cluster->canonical_title)) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            fn (string $token): bool => mb_strlen($token) > 3,
        ));

        return array_slice($tokens, 0, 4) ?: ['news'];
    }
}
