<?php

namespace App\Models;

use App\Enums\CircuitState;
use Illuminate\Database\Eloquent\Model;

class BudgetPeriod extends Model
{
    protected $fillable = [
        'period_start',
        'period_end',
        'limit_micros',
        'spent_micros',
        'reserved_micros',
        'breaker_state',
        'opened_at',
        'reset_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'limit_micros' => 'integer',
            'spent_micros' => 'integer',
            'reserved_micros' => 'integer',
            'breaker_state' => CircuitState::class,
            'opened_at' => 'datetime',
            'reset_at' => 'datetime',
        ];
    }

    public function remainingMicros(): int
    {
        return max(0, $this->limit_micros - $this->spent_micros - $this->reserved_micros);
    }

    public function committedMicros(): int
    {
        return $this->spent_micros + $this->reserved_micros;
    }
}
