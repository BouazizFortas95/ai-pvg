<?php

namespace App\Services\Automation;

use App\Contracts\AutomationStageInterface;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStageStatus;
use App\Enums\AutomationTrigger;
use App\Events\Automation\RunStarted;
use App\Events\Automation\StageCompleted;
use App\Events\Automation\StageFailed;
use App\Models\AutomationRun;
use App\Models\AutomationStageRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AutomationOrchestrator
{
    public function __construct(private readonly DryRunContext $dryRun) {}

    /**
     * Create an idempotent automation run. When a subject and active window
     * are supplied, the unique run key prevents duplicate work.
     *
     * @param  array<string, mixed>  $meta
     */
    public function createRun(
        string $pipeline,
        ?Model $subject = null,
        AutomationTrigger|string $trigger = AutomationTrigger::Manual,
        ?bool $dryRun = null,
        ?int $budgetLimitMicros = null,
        array $meta = [],
        ?string $activeWindow = null,
    ): AutomationRun {
        $pipeline = trim($pipeline);

        if ($pipeline === '') {
            throw new InvalidArgumentException('Automation pipeline cannot be empty.');
        }

        if (is_string($trigger)) {
            $trigger = AutomationTrigger::tryFrom($trigger) ?? AutomationTrigger::Manual;
        }

        if ($subject !== null && $activeWindow !== null) {
            $existing = AutomationRun::query()
                ->where('pipeline', $pipeline)
                ->where('subject_type', $subject->getMorphClass())
                ->where('subject_id', $subject->getKey())
                ->where('active_window', $activeWindow)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        return AutomationRun::create([
            'pipeline' => $pipeline,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'status' => AutomationRunStatus::Pending,
            'dry_run' => $dryRun ?? $this->dryRun->isDryRun(),
            'trigger' => $trigger,
            'budget_limit_micros' => $budgetLimitMicros
                ?? (int) config('automation.budget.per_run_limit_micros', 250_000),
            'budget_spent_micros' => 0,
            'budget_reserved_micros' => 0,
            'meta' => $meta === [] ? null : $meta,
            'active_window' => $activeWindow,
        ]);
    }

    /**
     * Execute one stage, persist its ledger row, and record the run state.
     * Provider calls belong inside the stage; this method never performs
     * network calls itself.
     *
     * @param  array<string, mixed>  $payload
     */
    public function executeStage(
        AutomationRun $run,
        string $stage,
        AutomationStageInterface $implementation,
        array $payload = [],
    ): StageResult {
        $stage = trim($stage);

        if ($stage === '') {
            throw new InvalidArgumentException('Automation stage name cannot be empty.');
        }

        $run->refresh();

        if ($run->isTerminal()) {
            throw new RuntimeException('Cannot execute a stage on a terminal automation run.');
        }

        $maxAttempts = max(1, (int) config('automation.stages.max_attempts', 3));
        $attempt = ((int) AutomationStageRun::query()
            ->where('automation_run_id', $run->getKey())
            ->where('stage', $stage)
            ->max('attempt')) + 1;

        if ($attempt > $maxAttempts) {
            throw new RuntimeException("Stage [{$stage}] has reached its maximum attempt count.");
        }

        $started = false;

        /** @var AutomationStageRun $stageRun */
        $stageRun = DB::transaction(function () use ($run, $stage, $attempt, $maxAttempts, $payload, &$started): AutomationStageRun {
            $lockedRun = AutomationRun::query()->lockForUpdate()->findOrFail($run->getKey());

            if ($lockedRun->isTerminal()) {
                throw new RuntimeException('Cannot execute a stage on a terminal automation run.');
            }

            if ($lockedRun->status === AutomationRunStatus::Pending) {
                $lockedRun->forceFill([
                    'status' => AutomationRunStatus::Running,
                    'started_at' => $lockedRun->started_at ?? now(),
                ])->save();
                $started = true;
            } elseif ($lockedRun->status === AutomationRunStatus::Paused) {
                throw new RuntimeException('Cannot execute a stage while the automation run is paused.');
            }

            return $lockedRun->stageRuns()->create([
                'stage' => $stage,
                'status' => AutomationStageStatus::Running,
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'input_ref' => $payload === [] ? null : $payload,
                'started_at' => now(),
            ]);
        });

        if ($started) {
            event(new RunStarted($run->fresh()));
        }

        $context = new AutomationContext(
            run: $run->fresh(),
            stage: $stage,
            dryRun: $this->dryRun->isDryRun($run),
            payload: $payload,
        );

        try {
            $result = $implementation->run($context);
        } catch (Throwable $exception) {
            $result = StageResult::failure(
                $exception->getMessage(),
                $implementation->isRetryable(),
            );
        }

        DB::transaction(function () use ($run, $stageRun, $result, $attempt, $maxAttempts): void {
            $stageRun->forceFill([
                'status' => $result->status,
                'output_ref' => $result->output === [] ? null : $result->output,
                'cost_micros' => $result->costMicros,
                'completed_at' => now(),
                'error_message' => $result->errorMessage,
            ])->save();

            $lockedRun = AutomationRun::query()->lockForUpdate()->findOrFail($run->getKey());
            $lockedRun->budget_spent_micros += $result->costMicros;

            if ($result->status === AutomationStageStatus::Failed) {
                $lockedRun->error_code = 'stage_failed';
                $lockedRun->error_message = $result->errorMessage;

                if (! $result->retryable || $attempt >= $maxAttempts) {
                    $lockedRun->status = AutomationRunStatus::Failed;
                    $lockedRun->completed_at = now();
                }
            }

            $lockedRun->save();
        });

        $stageRun->refresh();

        if ($result->status === AutomationStageStatus::Failed) {
            event(new StageFailed($stageRun, $result));
        } else {
            event(new StageCompleted($stageRun, $result));
        }

        return $result;
    }

    /**
     * Mark a run completed after all pipeline stages have succeeded.
     */
    public function completeRun(AutomationRun $run): AutomationRun
    {
        $run->forceFill([
            'status' => AutomationRunStatus::Completed,
            'completed_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        return $run->fresh();
    }
}
