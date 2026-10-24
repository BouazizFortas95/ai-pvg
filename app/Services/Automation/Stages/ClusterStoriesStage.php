<?php

namespace App\Services\Automation\Stages;

use App\Contracts\AutomationStageInterface;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\Clustering\StoryClusteringService;
use App\Services\Automation\StageResult;
use App\Services\Automation\Triage\TriageGate;
use Carbon\CarbonImmutable;
use Throwable;

class ClusterStoriesStage implements AutomationStageInterface
{
    public function __construct(
        private readonly TriageGate $gate,
        private readonly StoryClusteringService $clustering,
    ) {}

    public function run(AutomationContext $context): StageResult
    {
        if (! $this->gate->enabled()) {
            return StageResult::skipped('triage_disabled');
        }

        try {
            $clusters = $this->clustering->cluster($this->asOf($context));
        } catch (Throwable $exception) {
            return StageResult::failure($exception->getMessage());
        }

        return StageResult::success([
            'cluster_count' => count($clusters),
            'clusters' => collect($clusters)
                ->map(fn ($cluster): array => [
                    'story_cluster_id' => (int) $cluster->getKey(),
                    'canonical_title' => (string) $cluster->canonical_title,
                    'source_count' => (int) $cluster->source_count,
                    'confidence' => (float) $cluster->confidence,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function isRetryable(): bool
    {
        return false;
    }

    public function costEstimate(AutomationContext $context): int
    {
        return 0;
    }

    private function asOf(AutomationContext $context): ?CarbonImmutable
    {
        $asOf = $context->payload['as_of'] ?? null;

        return is_string($asOf) && trim($asOf) !== ''
            ? CarbonImmutable::parse($asOf)
            : null;
    }
}
