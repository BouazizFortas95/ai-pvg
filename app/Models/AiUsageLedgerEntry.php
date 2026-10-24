<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLedgerEntry extends Model
{
    protected $table = 'ai_usage_ledger';

    public const UPDATED_AT = null;

    protected $fillable = [
        'automation_run_id',
        'provider',
        'model',
        'purpose',
        'tokens_in',
        'tokens_out',
        'cost_micros',
    ];

    protected function casts(): array
    {
        return [
            'tokens_in' => 'integer',
            'tokens_out' => 'integer',
            'cost_micros' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }
}
