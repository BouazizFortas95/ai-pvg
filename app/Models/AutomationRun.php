<?php

namespace App\Models;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AutomationRun extends Model
{
    protected $fillable = [
        'pipeline',
        'subject_type',
        'subject_id',
        'status',
        'dry_run',
        'trigger',
        'budget_limit_micros',
        'budget_spent_micros',
        'budget_reserved_micros',
        'started_at',
        'completed_at',
        'error_code',
        'error_message',
        'meta',
        'active_window',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationRunStatus::class,
            'trigger' => AutomationTrigger::class,
            'dry_run' => 'boolean',
            'budget_limit_micros' => 'integer',
            'budget_spent_micros' => 'integer',
            'budget_reserved_micros' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function stageRuns(): HasMany
    {
        return $this->hasMany(AutomationStageRun::class);
    }

    public function usageEntries(): HasMany
    {
        return $this->hasMany(AiUsageLedgerEntry::class);
    }

    public function approvalGates(): MorphMany
    {
        return $this->morphMany(ApprovalGate::class, 'gateable');
    }

    public function isTerminal(): bool
    {
        return $this->status instanceof AutomationRunStatus
            && $this->status->isTerminal();
    }
}
