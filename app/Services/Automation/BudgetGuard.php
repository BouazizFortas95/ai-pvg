<?php

namespace App\Services\Automation;

use App\Enums\AiUsagePurpose;
use App\Enums\CircuitState;
use App\Events\Automation\BudgetThresholdReached;
use App\Exceptions\Automation\BudgetExceededException;
use App\Models\AiUsageLedgerEntry;
use App\Models\AutomationRun;
use App\Models\BudgetPeriod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class BudgetGuard
{
    public function __construct(private readonly CircuitBreaker $breaker) {}

    /**
     * Atomically reserve estimated spend against the current budget period
     * and, when provided, the run budget. SQLite has database-level locking,
     * so the guard uses a conditional UPDATE and checks affected rows.
     */
    public function authorize(int $estimatedCostMicros, ?AutomationRun $run = null): BudgetReservation
    {
        if ($estimatedCostMicros < 0) {
            throw new InvalidArgumentException('Estimated cost cannot be negative.');
        }

        $perCallLimit = (int) config('automation.budget.per_call_limit_micros', 50_000);

        if ($perCallLimit > 0 && $estimatedCostMicros > $perCallLimit) {
            throw BudgetExceededException::perCall($estimatedCostMicros, $perCallLimit);
        }

        $period = $this->currentPeriod();

        if ($period === null) {
            throw new RuntimeException('Unable to initialize the current automation budget period.');
        }

        if ($this->breaker->isOpen(CircuitBreaker::BUDGET_PROVIDER)) {
            throw BudgetExceededException::breakerOpen();
        }

        try {
            return DB::transaction(function () use ($estimatedCostMicros, $run, $period): BudgetReservation {
                $affected = DB::table('budget_periods')
                    ->where('id', $period->getKey())
                    ->whereRaw(
                        '(spent_micros + reserved_micros + ?) <= limit_micros',
                        [$estimatedCostMicros],
                    )
                    ->update([
                        'reserved_micros' => DB::raw('reserved_micros + '.(int) $estimatedCostMicros),
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw BudgetExceededException::monthly($estimatedCostMicros, $period->limit_micros);
                }

                if ($run !== null) {
                    $runLimit = (int) $run->budget_limit_micros;

                    if ($runLimit <= 0) {
                        $runLimit = (int) config('automation.budget.per_run_limit_micros', 250_000);
                    }

                    $runQuery = DB::table('automation_runs')
                        ->where('id', $run->getKey());

                    if ($runLimit > 0) {
                        $runQuery->whereRaw(
                            '(budget_spent_micros + budget_reserved_micros + ?) <= ?',
                            [$estimatedCostMicros, $runLimit],
                        );
                    }

                    $runAffected = $runQuery->update([
                        'budget_reserved_micros' => DB::raw('budget_reserved_micros + '.(int) $estimatedCostMicros),
                        'updated_at' => now(),
                    ]);

                    if ($runAffected !== 1) {
                        throw BudgetExceededException::perRun($estimatedCostMicros, $runLimit);
                    }
                }

                return new BudgetReservation(
                    periodId: (int) $period->getKey(),
                    amountMicros: $estimatedCostMicros,
                    runId: $run?->getKey(),
                    provider: null,
                );
            });
        } catch (BudgetExceededException $exception) {
            if ($exception->tripsBreaker) {
                $this->breaker->open(CircuitBreaker::BUDGET_PROVIDER, $exception->getMessage());
            }

            throw $exception;
        }
    }

    /**
     * Convert a reservation into committed spend. Actual cost may be lower
     * than the estimate; requesting a higher actual cost requires a new
     * authorization so the monthly cap can never be bypassed at settlement.
     *
     * @param  array{provider?: string, model?: string|null, purpose?: AiUsagePurpose|string, tokens_in?: int, tokens_out?: int}  $usage
     */
    public function settle(BudgetReservation $reservation, int $actualCostMicros, array $usage = []): BudgetPeriod
    {
        if ($actualCostMicros < 0) {
            throw new InvalidArgumentException('Actual cost cannot be negative.');
        }

        if ($actualCostMicros > $reservation->amountMicros) {
            throw new InvalidArgumentException(
                'Actual cost exceeds the reserved amount; authorize the additional spend first.',
            );
        }

        $usageRow = $this->normalizeUsage($reservation, $actualCostMicros, $usage);

        $period = DB::transaction(function () use ($reservation, $actualCostMicros, $usageRow): BudgetPeriod {
            $affected = DB::table('budget_periods')
                ->where('id', $reservation->periodId)
                ->where('reserved_micros', '>=', $reservation->amountMicros)
                ->update([
                    'reserved_micros' => DB::raw('reserved_micros - '.(int) $reservation->amountMicros),
                    'spent_micros' => DB::raw('spent_micros + '.(int) $actualCostMicros),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new RuntimeException('Budget reservation is no longer valid.');
            }

            if ($reservation->runId !== null) {
                $runAffected = DB::table('automation_runs')
                    ->where('id', $reservation->runId)
                    ->where('budget_reserved_micros', '>=', $reservation->amountMicros)
                    ->update([
                        'budget_reserved_micros' => DB::raw('budget_reserved_micros - '.(int) $reservation->amountMicros),
                        'budget_spent_micros' => DB::raw('budget_spent_micros + '.(int) $actualCostMicros),
                        'updated_at' => now(),
                    ]);

                if ($runAffected !== 1) {
                    throw new RuntimeException('Run budget reservation is no longer valid.');
                }
            }

            if ($usageRow !== null) {
                AiUsageLedgerEntry::create($usageRow);
            }

            return BudgetPeriod::query()->findOrFail($reservation->periodId);
        });

        if ($this->breaker->state(CircuitBreaker::BUDGET_PROVIDER) === CircuitState::HalfOpen) {
            $this->breaker->close(CircuitBreaker::BUDGET_PROVIDER);
        }

        $this->maybeDispatchThresholdEvent($period);

        return $period;
    }

    public function release(BudgetReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $affected = DB::table('budget_periods')
                ->where('id', $reservation->periodId)
                ->where('reserved_micros', '>=', $reservation->amountMicros)
                ->update([
                    'reserved_micros' => DB::raw('reserved_micros - '.(int) $reservation->amountMicros),
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new RuntimeException('Budget reservation is no longer valid.');
            }

            if ($reservation->runId !== null) {
                $runAffected = DB::table('automation_runs')
                    ->where('id', $reservation->runId)
                    ->where('budget_reserved_micros', '>=', $reservation->amountMicros)
                    ->update([
                        'budget_reserved_micros' => DB::raw('budget_reserved_micros - '.(int) $reservation->amountMicros),
                        'updated_at' => now(),
                    ]);

                if ($runAffected !== 1) {
                    throw new RuntimeException('Run budget reservation is no longer valid.');
                }
            }
        });
    }

    public function currentPeriod(bool $create = true): ?BudgetPeriod
    {
        $periodStart = now()->startOfMonth()->toDateString();
        $period = BudgetPeriod::query()->whereDate('period_start', $periodStart)->first();

        if ($period !== null || ! $create) {
            return $period;
        }

        DB::table('budget_periods')->insertOrIgnore([
            'period_start' => $periodStart,
            'period_end' => now()->endOfMonth()->toDateString(),
            'limit_micros' => max(0, (int) config('automation.budget.monthly_limit_micros', 5_000_000)),
            'spent_micros' => 0,
            'reserved_micros' => 0,
            'breaker_state' => CircuitState::Closed->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return BudgetPeriod::query()->whereDate('period_start', $periodStart)->first();
    }

    public function remainingMicros(): int
    {
        return $this->currentPeriod(false)?->remainingMicros()
            ?? max(0, (int) config('automation.budget.monthly_limit_micros', 5_000_000));
    }

    /**
     * Read-only snapshot used by diagnostics. Never creates a period.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $period = $this->currentPeriod(false);

        if ($period === null) {
            return [
                'initialized' => false,
                'limit_micros' => (int) config('automation.budget.monthly_limit_micros', 5_000_000),
                'spent_micros' => 0,
                'reserved_micros' => 0,
                'remaining_micros' => (int) config('automation.budget.monthly_limit_micros', 5_000_000),
                'breaker_state' => CircuitState::Closed->value,
            ];
        }

        return [
            'initialized' => true,
            'period_start' => $period->period_start?->toDateString(),
            'period_end' => $period->period_end?->toDateString(),
            'limit_micros' => $period->limit_micros,
            'spent_micros' => $period->spent_micros,
            'reserved_micros' => $period->reserved_micros,
            'remaining_micros' => $period->remainingMicros(),
            'breaker_state' => $period->breaker_state?->value,
        ];
    }

    /**
     * @param  array{provider?: string, model?: string|null, purpose?: AiUsagePurpose|string, tokens_in?: int, tokens_out?: int}  $usage
     * @return array<string, mixed>|null
     */
    private function normalizeUsage(BudgetReservation $reservation, int $actualCostMicros, array $usage): ?array
    {
        if ($usage === []) {
            return null;
        }

        $provider = trim((string) ($usage['provider'] ?? ''));

        if ($provider === '') {
            throw new InvalidArgumentException('AI usage ledger entries require a provider.');
        }

        $purpose = $usage['purpose'] ?? null;
        $purpose = $purpose instanceof AiUsagePurpose ? $purpose->value : (string) $purpose;

        if (AiUsagePurpose::tryFrom($purpose) === null) {
            throw new InvalidArgumentException('AI usage ledger entries require a valid purpose.');
        }

        return [
            'automation_run_id' => $reservation->runId,
            'provider' => $provider,
            'model' => $usage['model'] ?? null,
            'purpose' => $purpose,
            'tokens_in' => max(0, (int) ($usage['tokens_in'] ?? 0)),
            'tokens_out' => max(0, (int) ($usage['tokens_out'] ?? 0)),
            'cost_micros' => $actualCostMicros,
        ];
    }

    private function maybeDispatchThresholdEvent(BudgetPeriod $period): void
    {
        $threshold = max(1, min(100, (int) config('automation.budget.alert_at_percent', 80)));

        if ($period->limit_micros <= 0) {
            return;
        }

        $committed = $period->committedMicros();
        $percentage = (int) floor(($committed / $period->limit_micros) * 100);

        if ($percentage < $threshold) {
            return;
        }

        $cacheKey = 'automation:budget-threshold:'.$period->getKey().':'.$threshold;

        if (Cache::add($cacheKey, true, now()->endOfMonth())) {
            event(new BudgetThresholdReached($period, $threshold, $committed));
        }
    }
}
