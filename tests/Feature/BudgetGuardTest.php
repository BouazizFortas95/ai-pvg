<?php

namespace Tests\Feature;

use App\Enums\AiUsagePurpose;
use App\Enums\CircuitState;
use App\Exceptions\Automation\BudgetExceededException;
use App\Models\AutomationRun;
use App\Models\BudgetPeriod;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class BudgetGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        config([
            'automation.budget.monthly_limit_micros' => 5_000,
            'automation.budget.per_run_limit_micros' => 2_000,
            'automation.budget.per_call_limit_micros' => 1_000,
            'automation.budget.alert_at_percent' => 80,
            'automation.circuit_breaker.cooldown_seconds' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_authorize_atomically_reserves_the_monthly_budget(): void
    {
        $reservation = app(BudgetGuard::class)->authorize(400);

        $this->assertSame(400, $reservation->amountMicros);
        $this->assertDatabaseHas('budget_periods', [
            'period_start' => '2026-10-01',
            'limit_micros' => 5_000,
            'spent_micros' => 0,
            'reserved_micros' => 400,
            'breaker_state' => CircuitState::Closed->value,
        ]);
    }

    public function test_monthly_cap_is_enforced_and_opens_the_budget_breaker(): void
    {
        config(['automation.budget.monthly_limit_micros' => 500]);

        $guard = app(BudgetGuard::class);
        $guard->authorize(400);

        try {
            $guard->authorize(200);
            $this->fail('A monthly budget breach should have been rejected.');
        } catch (BudgetExceededException $exception) {
            $this->assertStringContainsString('Monthly automation budget exceeded', $exception->getMessage());
        }

        $this->assertDatabaseHas('budget_periods', [
            'reserved_micros' => 400,
            'spent_micros' => 0,
            'breaker_state' => CircuitState::Open->value,
        ]);

        $this->assertSame(
            CircuitState::Open,
            app(CircuitBreaker::class)->state(CircuitBreaker::BUDGET_PROVIDER),
        );
    }

    public function test_open_budget_breaker_blocks_further_paid_calls(): void
    {
        config(['automation.budget.monthly_limit_micros' => 500]);

        $guard = app(BudgetGuard::class);
        $guard->authorize(400);

        try {
            $guard->authorize(200);
        } catch (BudgetExceededException) {
            // Expected: this opens the breaker.
        }

        $this->expectException(BudgetExceededException::class);
        $this->expectExceptionMessage('breaker is open');

        $guard->authorize(1);
    }

    public function test_per_run_cap_rolls_back_the_monthly_reservation(): void
    {
        $run = $this->makeRun(500);
        $guard = app(BudgetGuard::class);

        $guard->authorize(400, $run);

        try {
            $guard->authorize(200, $run);
            $this->fail('A per-run budget breach should have been rejected.');
        } catch (BudgetExceededException $exception) {
            $this->assertStringContainsString('Per-run budget exceeded', $exception->getMessage());
        }

        $this->assertDatabaseHas('budget_periods', [
            'reserved_micros' => 400,
            'spent_micros' => 0,
        ]);
        $this->assertDatabaseHas('automation_runs', [
            'id' => $run->getKey(),
            'budget_reserved_micros' => 400,
            'budget_spent_micros' => 0,
        ]);
    }

    public function test_per_call_cap_is_rejected_before_any_reservation(): void
    {
        $this->expectException(BudgetExceededException::class);
        $this->expectExceptionMessage('Per-call budget exceeded');

        try {
            app(BudgetGuard::class)->authorize(1_001);
        } finally {
            $this->assertDatabaseCount('budget_periods', 0);
        }
    }

    public function test_settle_commits_actual_cost_and_writes_usage_ledger(): void
    {
        $run = $this->makeRun(2_000);
        $guard = app(BudgetGuard::class);

        $reservation = $guard->authorize(500, $run);
        $period = $guard->settle($reservation, 300, [
            'provider' => 'ollama',
            'model' => 'llama3.2',
            'purpose' => AiUsagePurpose::Script,
            'tokens_in' => 120,
            'tokens_out' => 80,
        ]);

        $this->assertSame(300, $period->spent_micros);
        $this->assertSame(0, $period->reserved_micros);

        $this->assertDatabaseHas('budget_periods', [
            'id' => $period->getKey(),
            'spent_micros' => 300,
            'reserved_micros' => 0,
        ]);
        $this->assertDatabaseHas('automation_runs', [
            'id' => $run->getKey(),
            'budget_spent_micros' => 300,
            'budget_reserved_micros' => 0,
        ]);
        $this->assertDatabaseHas('ai_usage_ledger', [
            'automation_run_id' => $run->getKey(),
            'provider' => 'ollama',
            'model' => 'llama3.2',
            'purpose' => 'script',
            'tokens_in' => 120,
            'tokens_out' => 80,
            'cost_micros' => 300,
        ]);
    }

    public function test_release_restores_reserved_budget_without_spend(): void
    {
        $run = $this->makeRun(2_000);
        $guard = app(BudgetGuard::class);

        $reservation = $guard->authorize(500, $run);
        $guard->release($reservation);

        $this->assertDatabaseHas('budget_periods', [
            'reserved_micros' => 0,
            'spent_micros' => 0,
        ]);
        $this->assertDatabaseHas('automation_runs', [
            'id' => $run->getKey(),
            'budget_reserved_micros' => 0,
            'budget_spent_micros' => 0,
        ]);
    }

    public function test_settlement_cannot_exceed_the_reservation(): void
    {
        $guard = app(BudgetGuard::class);
        $reservation = $guard->authorize(100);

        try {
            $guard->settle($reservation, 101);
            $this->fail('Settlement above the reservation should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('exceeds the reserved amount', $exception->getMessage());
        }

        $this->assertDatabaseHas('budget_periods', [
            'reserved_micros' => 100,
            'spent_micros' => 0,
        ]);
    }

    public function test_current_period_is_stable_and_uses_the_configured_limit(): void
    {
        $guard = app(BudgetGuard::class);

        $first = $guard->currentPeriod();
        $second = $guard->currentPeriod();

        $this->assertInstanceOf(BudgetPeriod::class, $first);
        $this->assertSame($first->getKey(), $second?->getKey());
        $this->assertSame(5_000, $first->limit_micros);
        $this->assertSame('2026-10-31', $first->period_end?->toDateString());
        $this->assertDatabaseCount('budget_periods', 1);
    }

    private function makeRun(int $limitMicros): AutomationRun
    {
        return AutomationRun::create([
            'pipeline' => 'test_pipeline',
            'status' => 'pending',
            'dry_run' => true,
            'trigger' => 'manual',
            'budget_limit_micros' => $limitMicros,
        ]);
    }
}
