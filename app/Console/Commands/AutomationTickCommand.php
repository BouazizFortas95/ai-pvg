<?php

namespace App\Console\Commands;

use App\Services\Automation\ApprovalService;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\CircuitBreaker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AutomationTickCommand extends Command
{
    protected $signature = 'ai-pvg:automation:tick';

    protected $description = 'Idempotent control-plane tick for automation budget and approvals';

    public function handle(BudgetGuard $budget, ApprovalService $approvals, CircuitBreaker $breaker): int
    {
        if (config('automation.enabled') !== true) {
            $this->line('Automation disabled; tick is a no-op.');

            return self::SUCCESS;
        }

        if (! Schema::hasTable('budget_periods') || ! Schema::hasTable('approval_gates')) {
            $this->warn('Automation is enabled but control-plane tables are missing. Run migrations first.');

            return self::FAILURE;
        }

        $period = $budget->currentPeriod();
        $expired = $approvals->expirePending();
        $breakerState = $breaker->state(CircuitBreaker::BUDGET_PROVIDER);

        $this->line(sprintf(
            'Automation tick complete: budget_period=%s expired_approvals=%d budget_breaker=%s',
            $period?->period_start?->toDateString() ?? 'unavailable',
            $expired,
            $breakerState->value,
        ));

        return self::SUCCESS;
    }
}
