<?php

namespace App\Models;

use App\Services\Pipeline\PipelineStepService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineRun extends Model
{
    protected $fillable = [
        'run_id',
        'status',
        'current_step',
        'completed_steps',
        'options',
        'user_id',
    ];

    protected $casts = [
        'completed_steps' => 'array',
        'options' => 'array',
        'current_step' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineRunStage::class);
    }

    public function stageFor(int $stepNumber): ?PipelineRunStage
    {
        return $this->stages()->where('step_number', $stepNumber)->latest()->first();
    }

    public function markStepQueued(int $stepNumber, string $stepName, ?string $jobId = null): PipelineRunStage
    {
        $this->update([
            'status' => 'running',
            'current_step' => $stepNumber,
        ]);

        return $this->stages()->updateOrCreate(
            ['step_number' => $stepNumber],
            [
                'step_name' => $stepName,
                'status' => 'queued',
                'job_id' => $jobId,
                'queued_at' => now(),
                'started_at' => null,
                'heartbeat_at' => null,
                'completed_at' => null,
                'error_message' => null,
                'fix_hint' => null,
            ]
        );
    }

    public function markStepRunning(int $stepNumber, string $stepName, ?string $jobId = null): PipelineRunStage
    {
        $this->update([
            'status' => 'running',
            'current_step' => $stepNumber,
        ]);

        return $this->stages()->updateOrCreate(
            ['step_number' => $stepNumber],
            [
                'step_name' => $stepName,
                'status' => 'running',
                'job_id' => $jobId,
                'started_at' => now(),
                'heartbeat_at' => now(),
                'completed_at' => null,
                'error_message' => null,
                'fix_hint' => null,
            ]
        );
    }

    public function markStepCompleted(int $stepNumber, string $log, array $artifacts = []): PipelineRunStage
    {
        $completedSteps = $this->completed_steps ?? [];
        if (! in_array($stepNumber, $completedSteps, true)) {
            $completedSteps[] = $stepNumber;
            sort($completedSteps);
        }

        $nextStep = min(6, $stepNumber + 1);

        $this->update([
            'status' => count($completedSteps) >= 6 ? 'completed' : 'running',
            'completed_steps' => $completedSteps,
            'current_step' => $nextStep,
        ]);

        /** @var PipelineRunStage $stage */
        $stage = $this->stages()->firstOrNew(['step_number' => $stepNumber]);
        $stage->fill([
            'step_name' => PipelineStepService::STEP_NAMES[$stepNumber] ?? 'step_'.$stepNumber,
            'status' => 'completed',
            'completed_at' => now(),
            'log' => $log,
            'artifacts' => $artifacts,
            'error_message' => null,
            'fix_hint' => null,
        ])->save();

        return $stage;
    }

    public function markStepFailed(int $stepNumber, string $errorMessage, ?string $fixHint = null, ?string $log = null): PipelineRunStage
    {
        $this->update([
            'status' => 'failed',
        ]);

        /** @var PipelineRunStage $stage */
        $stage = $this->stages()->firstOrNew(['step_number' => $stepNumber]);
        $stage->fill([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $errorMessage,
            'fix_hint' => $fixHint,
            'log' => $log ?: ($stage->log ?? ''),
        ])->save();

        return $stage;
    }

    public function cancelStep(int $stepNumber): ?PipelineRunStage
    {
        $stage = $this->stages()->where('step_number', $stepNumber)->first();
        if ($stage && in_array($stage->status, ['pending', 'running'], true)) {
            $stage->update([
                'status' => 'cancelled',
                'completed_at' => now(),
                'error_message' => 'Step cancelled by operator.',
            ]);

            $this->update(['status' => 'cancelled']);
        }

        return $stage;
    }
}
