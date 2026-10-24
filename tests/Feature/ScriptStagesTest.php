<?php

namespace Tests\Feature;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStageStatus;
use App\Models\ScriptFactCheck;
use App\Models\ScriptVariant;
use App\Models\VideoScript;
use App\Services\Automation\AutomationOrchestrator;
use App\Services\Automation\PipelineRegistry;
use App\Services\Automation\Stages\ComposeScriptStage;
use App\Services\Automation\Stages\FactCheckScriptStage;
use App\Services\Automation\Stages\GenerateScriptVariantsStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsScriptFixtures;
use Tests\TestCase;

class ScriptStagesTest extends TestCase
{
    use BuildsScriptFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_script_agent_pipeline_is_registered_with_three_stages(): void
    {
        $registry = app(PipelineRegistry::class);

        $this->assertTrue($registry->has('script_agents.pipeline'));
        $this->assertSame(
            ['generate_variants', 'compose_script', 'fact_check_script'],
            $registry->stages('script_agents.pipeline'),
        );
    }

    public function test_stages_skip_cleanly_when_the_gate_is_disabled(): void
    {
        config([
            'automation.script_agents.enabled' => false,
            'automation.dry_run' => false,
        ]);

        $cluster = $this->makeScriptCluster();
        $run = app(AutomationOrchestrator::class)->createRun(
            pipeline: 'script_agents.pipeline',
            subject: $cluster,
            dryRun: false,
            activeWindow: 'cluster-'.$cluster->getKey().':g03-v1',
        );

        foreach ([GenerateScriptVariantsStage::class, ComposeScriptStage::class, FactCheckScriptStage::class] as $stage) {
            $result = app(AutomationOrchestrator::class)->executeStage($run, class_basename($stage), app($stage));

            $this->assertSame(AutomationStageStatus::Skipped, $result->status);
            $this->assertSame('script_agents_disabled', $result->output['reason']);
        }

        $this->assertSame(0, VideoScript::count());
        $this->assertSame(0, ScriptVariant::count());
        $this->assertSame(0, ScriptFactCheck::count());
        $this->assertSame(AutomationRunStatus::Running, $run->fresh()->status);
    }

    public function test_enabled_stages_complete_in_order_with_grounded_output(): void
    {
        $this->enableScriptAgents();
        $cluster = $this->makeScriptCluster();
        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun(
            pipeline: 'script_agents.pipeline',
            subject: $cluster,
            dryRun: false,
            activeWindow: 'cluster-'.$cluster->getKey().':g03-v1',
        );

        $stages = [
            'generate_variants' => GenerateScriptVariantsStage::class,
            'compose_script' => ComposeScriptStage::class,
            'fact_check_script' => FactCheckScriptStage::class,
        ];

        foreach ($stages as $name => $class) {
            $result = $orchestrator->executeStage($run, $name, app($class));

            $this->assertSame(AutomationStageStatus::Completed, $result->status, $result->errorMessage ?? $name);
            $this->assertArrayHasKey('automation_run_id', $result->output);
            $this->assertFalse($result->output['dry_run']);
        }

        $run = $orchestrator->completeRun($run->fresh());

        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertSame(3, ScriptVariant::count());
        $this->assertGreaterThanOrEqual(3, ScriptFactCheck::count());
        $this->assertSame(1, VideoScript::count());
        $this->assertTrue(ScriptFactCheck::query()->where('verdict', 'supported')->exists());
    }
}
