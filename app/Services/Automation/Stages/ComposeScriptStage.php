<?php

namespace App\Services\Automation\Stages;

use App\Contracts\AutomationStageInterface;
use App\Services\AI\ScriptPipeline\ScriptAgentsGate;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\StageResult;
use Throwable;

class ComposeScriptStage implements AutomationStageInterface
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
            return StageResult::failure('ComposeScriptStage requires a StoryCluster, News, story_cluster_id, or news_id.');
        }

        $hookIndex = isset($context->payload['hook_index']) && is_numeric($context->payload['hook_index'])
            ? (int) $context->payload['hook_index']
            : null;

        try {
            $result = $this->pipeline->composeScript($source, $context->run, $hookIndex);
        } catch (Throwable $exception) {
            return StageResult::failure($exception->getMessage());
        }

        return StageResult::success([
            ...$result->toArray(),
            'planned_scenes' => $result->plannedScenes,
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
