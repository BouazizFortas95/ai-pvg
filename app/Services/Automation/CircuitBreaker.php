<?php

namespace App\Services\Automation;

use App\Enums\CircuitState;
use App\Enums\ProviderHealthEventType;
use App\Models\BudgetPeriod;
use App\Models\ProviderHealthEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class CircuitBreaker
{
    public const BUDGET_PROVIDER = 'budget';

    public function state(string $provider = self::BUDGET_PROVIDER): CircuitState
    {
        $provider = $this->normalizeProvider($provider);

        if ($provider === self::BUDGET_PROVIDER) {
            return $this->budgetState();
        }

        $payload = Cache::get($this->cacheKey($provider));

        if (! is_array($payload) || ! isset($payload['state'])) {
            return CircuitState::Closed;
        }

        $state = CircuitState::tryFrom((string) $payload['state']) ?? CircuitState::Closed;

        if ($state === CircuitState::Open
            && isset($payload['reset_at'])
            && Carbon::parse($payload['reset_at'])->isPast()) {
            $this->halfOpen($provider);

            return CircuitState::HalfOpen;
        }

        return $state;
    }

    public function isOpen(string $provider = self::BUDGET_PROVIDER): bool
    {
        return $this->state($provider) === CircuitState::Open;
    }

    public function canAttempt(string $provider = self::BUDGET_PROVIDER): bool
    {
        return $this->state($provider) !== CircuitState::Open;
    }

    public function open(string $provider, ?string $reason = null): void
    {
        $provider = $this->normalizeProvider($provider);
        $now = now();
        $resetAt = $now->copy()->addSeconds($this->cooldownSeconds());

        Cache::forever($this->cacheKey($provider), [
            'state' => CircuitState::Open->value,
            'opened_at' => $now->toIso8601String(),
            'reset_at' => $resetAt->toIso8601String(),
            'reason' => $reason !== null ? $this->sanitize($reason) : null,
        ]);

        if ($provider === self::BUDGET_PROVIDER) {
            $period = $this->currentBudgetPeriod();

            $period?->forceFill([
                'breaker_state' => CircuitState::Open,
                'opened_at' => $now,
                'reset_at' => $resetAt,
            ])->save();
        }
    }

    public function halfOpen(string $provider): void
    {
        $provider = $this->normalizeProvider($provider);

        Cache::forever($this->cacheKey($provider), [
            'state' => CircuitState::HalfOpen->value,
            'opened_at' => now()->toIso8601String(),
            'reset_at' => null,
        ]);

        if ($provider === self::BUDGET_PROVIDER) {
            $this->currentBudgetPeriod()?->forceFill([
                'breaker_state' => CircuitState::HalfOpen,
                'reset_at' => null,
            ])->save();
        }
    }

    public function close(string $provider): void
    {
        $provider = $this->normalizeProvider($provider);

        Cache::forever($this->cacheKey($provider), [
            'state' => CircuitState::Closed->value,
            'opened_at' => null,
            'reset_at' => null,
        ]);
        Cache::forget($this->failureKey($provider));

        if ($provider === self::BUDGET_PROVIDER) {
            $this->currentBudgetPeriod()?->forceFill([
                'breaker_state' => CircuitState::Closed,
                'opened_at' => null,
                'reset_at' => null,
            ])->save();
        }
    }

    public function recordFailure(
        string $provider,
        ProviderHealthEventType|string $eventType,
        ?int $httpStatus = null,
        ?int $retryAfterSeconds = null,
        ?string $message = null,
    ): void {
        $provider = $this->normalizeProvider($provider);
        $eventType = is_string($eventType)
            ? (ProviderHealthEventType::tryFrom($eventType) ?? ProviderHealthEventType::Error)
            : $eventType;

        ProviderHealthEvent::create([
            'provider' => $provider,
            'event_type' => $eventType,
            'http_status' => $httpStatus,
            'retry_after_seconds' => $retryAfterSeconds,
            'message_sanitized' => $message !== null ? $this->sanitize($message) : null,
        ]);

        $failureKey = $this->failureKey($provider);
        $window = max(1, (int) config('automation.circuit_breaker.failure_window_seconds', 3600));
        $failures = (int) Cache::get($failureKey, 0) + 1;

        Cache::put($failureKey, $failures, now()->addSeconds($window));

        if ($failures >= max(1, (int) config('automation.circuit_breaker.failure_threshold', 3))) {
            $this->open($provider, $message ?? "Circuit opened after {$failures} provider failures.");
        }
    }

    public function recordSuccess(string $provider): void
    {
        $this->close($provider);
    }

    public function failureCount(string $provider): int
    {
        return (int) Cache::get($this->failureKey($this->normalizeProvider($provider)), 0);
    }

    public function lastEvent(string $provider): ?ProviderHealthEvent
    {
        return ProviderHealthEvent::query()
            ->where('provider', $this->normalizeProvider($provider))
            ->latest('created_at')
            ->first();
    }

    private function budgetState(): CircuitState
    {
        $period = $this->currentBudgetPeriod();

        if ($period === null) {
            return CircuitState::Closed;
        }

        $state = $period->breaker_state instanceof CircuitState
            ? $period->breaker_state
            : CircuitState::tryFrom((string) $period->breaker_state) ?? CircuitState::Closed;

        if ($state === CircuitState::Open
            && $period->reset_at !== null
            && $period->reset_at->isPast()) {
            $period->forceFill([
                'breaker_state' => CircuitState::HalfOpen,
                'reset_at' => null,
            ])->save();

            return CircuitState::HalfOpen;
        }

        return $state;
    }

    private function currentBudgetPeriod(): ?BudgetPeriod
    {
        return BudgetPeriod::query()
            ->whereDate('period_start', now()->startOfMonth()->toDateString())
            ->first();
    }

    private function cacheKey(string $provider): string
    {
        return 'automation:circuit:'.now()->format('Y-m').':'.$provider;
    }

    private function failureKey(string $provider): string
    {
        return 'automation:circuit-failures:'.now()->format('Y-m').':'.$provider;
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('automation.circuit_breaker.cooldown_seconds', 900));
    }

    private function normalizeProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        if ($provider === '') {
            throw new InvalidArgumentException('Circuit breaker provider cannot be empty.');
        }

        return $provider;
    }

    private function sanitize(string $message): string
    {
        $message = strip_tags($message);
        $message = preg_replace('/(bearer|token|api[_-]?key)\s*[:=]?\s*[A-Za-z0-9._\-]+/i', '$1 [REDACTED]', $message) ?? $message;
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);

        return mb_substr($message, 0, 500);
    }
}
