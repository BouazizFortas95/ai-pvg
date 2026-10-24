<?php

namespace App\Services\Automation\Stages;

use App\Contracts\AutomationStageInterface;
use App\Services\AI\ScriptPipeline\ScriptAgentsGate;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\StageResult;
use Throwable;

class FactCheckScriptStage implements AutomationStageInterface
{
    use ResolvesScriptSource;

    public function __construct(
        private readonly ScriptAgentsGate $gate,
        private readonly ScriptPipelineService $pipeline,
    ) {}

    public function run(AutomationContext $context): StageResult
    {
        if (! $this->gate->enabled()) {
            return StageResult::skipped('script_agents_disabled');
        }

        $source = $this->resolveScriptSource($context);

        if ($source === null) {
            return StageResult::failure('FactCheckScriptStage requires a StoryCluster, News, story_cluster_id, or news_id.');
        }

        try {
            $result = $this->pipeline->factCheckScript($source, $context->run);
        } catch (Throwable $exception) {
            return StageResult::failure($exception->getMessage());
        }

        return StageResult::success([
            ...$result->toArray(),
            'planned_fact_checks' => $result->plannedFactChecks,
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
}
