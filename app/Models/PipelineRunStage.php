<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineRunStage extends Model
{
    protected $fillable = [
        'pipeline_run_id',
        'step_number',
        'step_name',
        'status',
        'job_id',
        'queued_at',
        'started_at',
        'heartbeat_at',
        'completed_at',
        'log',
        'error_message',
        'fix_hint',
        'artifacts',
    ];

    protected $casts = [
        'artifacts' => 'array',
        'step_number' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PipelineRun::class, 'pipeline_run_id');
    }

    public function isQueued(): bool
    {
        return $this->status === 'queued';
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isStalled(int $thresholdSeconds = 60): bool
    {
        if ($this->status !== 'running') {
            return false;
        }

        $reference = $this->heartbeat_at ?? $this->started_at;
        if (! $reference) {
            return false;
        }

        return (int) abs($reference->diffInSeconds(now())) > $thresholdSeconds;
    }

    public function touchHeartbeat(): void
    {
        $this->update(['heartbeat_at' => now()]);
    }

    public function queuedSeconds(): int
    {
        if (! $this->queued_at) {
            return 0;
        }

        $end = $this->started_at ?? now();

        return (int) abs($this->queued_at->diffInSeconds($end));
    }

    public function elapsedSeconds(): int
    {
        if ($this->started_at) {
            $end = $this->completed_at ?? now();

            return (int) abs($this->started_at->diffInSeconds($end));
        }

        if ($this->queued_at) {
            $end = $this->completed_at ?? now();

            return (int) abs($this->queued_at->diffInSeconds($end));
        }

        return 0;
    }
}

