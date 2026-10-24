<?php

namespace App\Models;

use App\Enums\AutomationStageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationStageRun extends Model
{
    protected $fillable = [
        'automation_run_id',
        'stage',
        'status',
        'attempt',
        'max_attempts',
        'input_ref',
        'output_ref',
        'cost_micros',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationStageStatus::class,
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'input_ref' => 'array',
            'output_ref' => 'array',
            'cost_micros' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }
}
