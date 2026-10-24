<?php

namespace App\Events\Automation;

use App\Models\BudgetPeriod;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BudgetThresholdReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public BudgetPeriod $period,
        public int $thresholdPercent,
        public int $committedMicros,
    ) {}
}
