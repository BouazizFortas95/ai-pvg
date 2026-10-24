<?php

namespace App\Services\Automation\Stages;

use App\Contracts\AutomationStageInterface;
use App\Models\StoryCluster;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\Greenlight\AutoGreenlightService;
use App\Services\Automation\StageResult;
use App\Services\Automation\Triage\TriageGate;
use Carbon\CarbonImmutable;
use Throwable;

class AutoGreenlightStage implements AutomationStageInterface
{
    public function __construct(
        private readonly TriageGate $gate,
        private readonly AutoGreenlightService $greenlight,
    ) {}

    public function run(AutomationContext $context): StageResult
    {
        if (! $this->gate->enabled()) {
            return StageResult::skipped('triage_disabled');
        }

        $cluster = $this->resolveCluster($context);

        if ($cluster === null) {
            return StageResult::failure('AutoGreenlightStage requires a StoryCluster subject or story_cluster_id payload.');
        }

        try {
            $result = $this->greenlight->evaluateCluster($cluster, $this->asOf($context));
        } catch (Throwable $exception) {
            return StageResult::failure($exception->getMessage());
        }

        return StageResult::success([
            ...$result->toArray(),
            'planned_script' => $result->plannedScript,
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

    private function resolveCluster(AutomationContext $context): ?StoryCluster
    {
        $subject = $context->run->subject;

        if ($subject instanceof StoryCluster) {
            return $subject;
        }

        $clusterId = $context->payload['story_cluster_id'] ?? $context->payload['cluster_id'] ?? null;

        return is_numeric($clusterId)
            ? StoryCluster::query()->with(['news.newsScore'])->find((int) $clusterId)
            : null;
    }

    private function asOf(AutomationContext $context): ?CarbonImmutable
    {
        $asOf = $context->payload['as_of'] ?? null;

        return is_string($asOf) && trim($asOf) !== ''
            ? CarbonImmutable::parse($asOf)
            : null;
    }
}
