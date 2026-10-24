<?php

namespace Tests\Feature;

use App\Contracts\AutomationStageInterface;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStageStatus;
use App\Enums\AutomationTrigger;
use App\Events\Automation\RunStarted;
use App\Events\Automation\StageCompleted;
use App\Events\Automation\StageFailed;
use App\Models\AutomationStageRun;
use App\Models\News;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\AutomationOrchestrator;
use App\Services\Automation\StageResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AutomationOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'automation.dry_run' => true,
            'automation.budget.per_run_limit_micros' => 2_000,
            'automation.stages.max_attempts' => 3,
        ]);
    }

    public function test_run_creation_is_idempotent_for_the_same_pipeline_subject_and_window(): void
    {
        $news = News::factory()->create();
        $orchestrator = app(AutomationOrchestrator::class);

        $first = $orchestrator->createRun(
            'test_pipeline',
            $news,
            AutomationTrigger::Scheduler,
            activeWindow: '2026-10-24T12',
        );
        $second = $orchestrator->createRun(
            'test_pipeline',
            $news,
            AutomationTrigger::Scheduler,
            activeWindow: '2026-10-24T12',
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertTrue($first->dry_run);
        $this->assertSame(AutomationRunStatus::Pending, $first->status);
        $this->assertSame(2_000, $first->budget_limit_micros);
    }

    public function test_execute_stage_writes_a_stage_ledger_row_and_updates_run_cost(): void
    {
        Event::fake([RunStarted::class, StageCompleted::class, StageFailed::class]);

        $news = News::factory()->create();
        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('test_pipeline', $news, AutomationTrigger::Scheduler);

        $result = $orchestrator->executeStage(
            $run,
            'score',
            $this->stage(StageResult::success(['score' => 95], 300)),
            ['news_id' => $news->getKey()],
        );

        $run = $run->fresh();
        $stageRun = AutomationStageRun::query()->sole();

        $this->assertTrue($result->successful());
        $this->assertSame(AutomationStageStatus::Completed, $stageRun->status);
        $this->assertSame('score', $stageRun->stage);
        $this->assertSame(1, $stageRun->attempt);
        $this->assertSame(300, $stageRun->cost_micros);
        $this->assertSame(['score' => 95], $stageRun->output_ref);
        $this->assertSame(AutomationRunStatus::Running, $run->status);
        $this->assertSame(300, $run->budget_spent_micros);
        $this->assertNotNull($run->started_at);

        Event::assertDispatched(RunStarted::class);
        Event::assertDispatched(StageCompleted::class);

        $completed = $orchestrator->completeRun($run);

        $this->assertSame(AutomationRunStatus::Completed, $completed->status);
        $this->assertNotNull($completed->completed_at);
    }

    public function test_non_retryable_stage_failure_marks_the_run_failed(): void
    {
        Event::fake([StageFailed::class]);

        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('test_pipeline', News::factory()->create());

        $result = $orchestrator->executeStage(
            $run,
            'score',
            $this->stage(StageResult::failure('Invalid payload', false, 25)),
        );

        $run = $run->fresh();

        $this->assertSame(AutomationStageStatus::Failed, $result->status);
        $this->assertSame(AutomationRunStatus::Failed, $run->status);
        $this->assertSame('stage_failed', $run->error_code);
        $this->assertSame('Invalid payload', $run->error_message);
        $this->assertSame(25, $run->budget_spent_micros);
        $this->assertNotNull($run->completed_at);

        Event::assertDispatched(StageFailed::class);
    }

    public function test_retryable_stage_failure_keeps_the_run_open_for_another_attempt(): void
    {
        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('test_pipeline', News::factory()->create());
        $stage = $this->stage(StageResult::failure('Temporary timeout', true), retryable: true);

        $orchestrator->executeStage($run, 'score', $stage);
        $orchestrator->executeStage($run, 'score', $stage);

        $this->assertSame(AutomationRunStatus::Running, $run->fresh()->status);
        $this->assertSame(2, AutomationStageRun::query()->where('stage', 'score')->count());
        $this->assertSame(2, (int) AutomationStageRun::query()->max('attempt'));
    }

    public function test_terminal_run_cannot_execute_more_stages(): void
    {
        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('test_pipeline', News::factory()->create());
        $orchestrator->completeRun($run);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('terminal automation run');

        $orchestrator->executeStage($run, 'score', $this->stage(StageResult::success()));
    }

    public function test_paused_run_cannot_execute_stages(): void
    {
        $orchestrator = app(AutomationOrchestrator::class);
        $run = $orchestrator->createRun('test_pipeline', News::factory()->create());
        $run->forceFill([
            'status' => AutomationRunStatus::Paused,
            'started_at' => now(),
        ])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('paused');

        $orchestrator->executeStage($run, 'score', $this->stage(StageResult::success()));
    }

    private function stage(StageResult $result, bool $retryable = false): AutomationStageInterface
    {
        return new class($result, $retryable) implements AutomationStageInterface
        {
            public function __construct(
                private readonly StageResult $result,
                private readonly bool $retryable,
            ) {}

            public function run(AutomationContext $context): StageResult
            {
                return $this->result;
            }

            public function isRetryable(): bool
            {
                return $this->retryable;
            }

            public function costEstimate(AutomationContext $context): int
            {
                return $this->result->costMicros;
            }
        };
    }
}
