<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Filament\Pages\PipelineRunnerPage;
use App\Jobs\ExecutePipelineStepJob;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\PipelineRun;
use App\Models\User;
use App\Models\VideoScript;
use App\Services\Pipeline\PipelineStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PipelineStepServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_apply_safety_flags_enables_local_flags_and_locks_publishing_off(): void
    {
        $service = app(PipelineStepService::class);
        $flags = $service->applySafetyFlags(['enable_avatar' => true, 'backend' => 'hyperframes']);

        $this->assertTrue($flags['triage.news_scoring_enabled']);
        $this->assertTrue($flags['script_agents.enabled']);
        $this->assertTrue($flags['captions.enabled']);
        $this->assertTrue($flags['assets.enabled']);
        $this->assertTrue($flags['formats.enabled']);
        $this->assertTrue($flags['render.hyperframes_enabled']);
        $this->assertTrue($flags['audio.pipeline_enabled']);
        $this->assertTrue($flags['avatar.enabled']);
        $this->assertFalse($flags['publishing.enabled']);

        // Check database
        $this->assertTrue(FeatureFlag::isEnabled('render.hyperframes_enabled'));
        $this->assertTrue(FeatureFlag::isEnabled('audio.pipeline_enabled'));
        $this->assertTrue(FeatureFlag::isEnabled('avatar.enabled'));
        $this->assertFalse(FeatureFlag::isEnabled('publishing.enabled'));
        $this->assertFalse((bool) config('automation.publishing.enabled'));
    }

    public function test_step_one_execution_records_run_and_stage(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('ai-pvg:seed-mock-cluster', [
                '--cluster-size' => 3,
                '--skip-triage' => true,
                '--skip-script' => true,
                '--skip-render' => true,
            ])
            ->andReturn(0);

        Artisan::shouldReceive('output')->andReturn('Seeded mock cluster.');

        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $result = $service->runStep1([], $run);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['step']);
        $this->assertStringContainsString('Step 1: Prepare & Load Breaking News', $result['log']);

        $this->assertDatabaseHas('pipeline_runs', [
            'id' => $run->id,
            'current_step' => 2,
        ]);

        $this->assertDatabaseHas('pipeline_run_stages', [
            'pipeline_run_id' => $run->id,
            'step_number' => 1,
            'step_name' => 'ingest',
            'status' => 'completed',
        ]);
    }

    public function test_dispatch_step_queues_job_and_creates_queued_stage(): void
    {
        Queue::fake();

        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $stage = $service->dispatchStep(4, ['caption_style' => 'karaoke'], $run);

        $this->assertSame('queued', $stage->status);
        $this->assertNotNull($stage->queued_at);
        $this->assertSame(4, $stage->step_number);
        $this->assertSame('audio', $stage->step_name);

        Queue::assertPushed(ExecutePipelineStepJob::class, function ($job) use ($stage) {
            return $job->stageId === $stage->id && $job->stepNumber === 4;
        });

        // Test idempotency: second dispatch returns existing stage without pushing another job
        $secondStage = $service->dispatchStep(4, ['caption_style' => 'karaoke'], $run);
        $this->assertSame($stage->id, $secondStage->id);
        Queue::assertPushed(ExecutePipelineStepJob::class, 1);
    }

    public function test_execute_pipeline_step_job_runs_service_and_marks_completed(): void
    {
        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $news = News::factory()->create(['status' => NewsStatus::Ready->value]);
        $script = VideoScript::factory()->create(['news_id' => $news->getKey(), 'status' => 'approved']);

        $stage = $run->markStepRunning(4, 'audio');

        $job = new ExecutePipelineStepJob(
            stageId: $stage->id,
            stepNumber: 4,
            options: ['script_id' => $script->getKey()]
        );

        $job->handle($service);

        $stage->refresh();
        $this->assertSame('completed', $stage->status);
        $this->assertNotNull($stage->completed_at);
        $this->assertStringContainsString('Step 4: Voiceover', $stage->log);
    }

    public function test_cancel_step_marks_stage_and_run_cancelled(): void
    {
        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $stage = $run->markStepRunning(4, 'audio');
        $this->assertSame('running', $stage->status);

        $service->cancelStep(4, $run);

        $stage->refresh();
        $run->refresh();

        $this->assertSame('cancelled', $stage->status);
        $this->assertSame('cancelled', $run->status);
        $this->assertStringContainsString('cancelled by operator', $stage->error_message);
    }

    public function test_cli_command_step_runner_parity(): void
    {
        $mockService = $this->mock(PipelineStepService::class);
        $run = PipelineRun::create([
            'run_id' => 'pvg_test_cli_run',
            'status' => 'pending',
            'current_step' => 1,
        ]);

        $mockService->shouldReceive('findOrCreateRun')
            ->andReturn($run);

        $mockService->shouldReceive('executeStep')
            ->with(1, $run, [])
            ->once()
            ->andReturn([
                'success' => true,
                'step' => 1,
                'log' => 'Step 1 log output',
                'artifacts' => [],
            ]);

        $this->artisan('ai-pvg:pipeline-step', ['step' => 1])
            ->expectsOutputToContain('AI-PVG Pipeline Step Runner — Step 1 (ingest)')
            ->expectsOutputToContain('Step 1 log output')
            ->expectsOutputToContain('Step 1 completed successfully.')
            ->assertSuccessful();

        $this->artisan('ai-pvg:pipeline-step', ['step' => 99])
            ->expectsOutputToContain('Invalid step number')
            ->assertFailed();
    }

    public function test_cli_command_step_runner_queue_flag(): void
    {
        Queue::fake();

        $this->artisan('ai-pvg:pipeline-step', ['step' => 4, '--queue' => true])
            ->expectsOutputToContain('dispatched as queued job')
            ->assertSuccessful();

        Queue::assertPushed(ExecutePipelineStepJob::class);
    }

    public function test_persistence_across_page_reloads(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // Pre-existing pipeline run with step 1 and 2 completed
        $run = PipelineRun::create([
            'run_id' => 'pvg_test_reload_run',
            'status' => 'running',
            'current_step' => 3,
            'completed_steps' => [1, 2],
        ]);

        $stage = $run->stages()->create([
            'step_number' => 2,
            'step_name' => 'triage',
            'status' => 'completed',
            'completed_at' => now(),
            'log' => 'Stage 2 completed log.',
        ]);

        Livewire::test(PipelineRunnerPage::class)
            ->assertSet('currentRunId', 'pvg_test_reload_run')
            ->assertSet('currentStep', 3)
            ->assertSet('completedSteps', [1, 2]);
    }

    public function test_reset_wizard_preserves_production_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $news = News::factory()->create(['status' => NewsStatus::Ready->value]);
        $script = VideoScript::factory()->create(['news_id' => $news->getKey()]);

        $run = PipelineRun::create([
            'run_id' => 'pvg_test_reset_run',
            'status' => 'running',
            'current_step' => 4,
            'completed_steps' => [1, 2, 3],
        ]);

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentRunId', $run->run_id)
            ->set('currentStep', 4)
            ->set('completedSteps', [1, 2, 3])
            ->call('resetWizard')
            ->assertSet('currentStep', 1)
            ->assertSet('completedSteps', [])
            ->assertSet('currentRunId', null);

        // Verify production news and scripts are untouched!
        $this->assertDatabaseHas('news', ['id' => $news->getKey()]);
        $this->assertDatabaseHas('video_scripts', ['id' => $script->getKey()]);

        // Run is marked cancelled
        $this->assertDatabaseHas('pipeline_runs', [
            'id' => $run->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_elapsed_seconds_computes_correctly_without_carbon_negative_clamping(): void
    {
        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $stage = $run->markStepRunning(4, 'audio');
        // Simulate started 35 seconds ago
        $stage->started_at = now()->subSeconds(35);
        $stage->save();

        $this->assertSame(35, $stage->elapsedSeconds());

        // Test completed stage elapsed time
        $stage->completed_at = $stage->started_at->copy()->addSeconds(18);
        $stage->save();

        $this->assertSame(18, $stage->elapsedSeconds());
    }

    public function test_stage_stall_detection_and_worker_monitoring(): void
    {
        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $stage = $run->markStepQueued(6, 'render');
        $stage->queued_at = now()->subSeconds(20);
        $stage->save();

        $this->assertTrue($stage->isQueued());
        $this->assertGreaterThanOrEqual(20, $stage->queuedSeconds());

        // Simulate transition to running
        $stage->update([
            'status' => 'running',
            'started_at' => now()->subSeconds(90),
            'heartbeat_at' => now()->subSeconds(90),
        ]);

        $this->assertTrue($stage->isStalled(60));

        // Touch heartbeat
        $stage->touchHeartbeat();
        $this->assertFalse($stage->isStalled(60));
    }

    public function test_process_running_step_now_executes_step_synchronously(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $news = News::factory()->create(['status' => NewsStatus::Ready->value]);
        $script = VideoScript::factory()->create(['news_id' => $news->getKey(), 'status' => 'approved']);

        $service = app(PipelineStepService::class);
        $run = $service->findOrCreateRun();

        $stage = $service->dispatchStep(4, ['script_id' => $script->getKey()], $run);

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentRunId', $run->run_id)
            ->set('runningStep', 4)
            ->set('runningStageId', $stage->id)
            ->set('runningStatus', 'queued')
            ->set('selectedScriptId', $script->getKey())
            ->call('processRunningStepNow')
            ->assertSet('runningStep', null)
            ->assertSet('runningStageId', null);

        $stage->refresh();
        $this->assertSame('completed', $stage->status);
    }
}
