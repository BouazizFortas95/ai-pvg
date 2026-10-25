<?php

namespace App\Services\Automation;

use App\Enums\CircuitState;
use App\Services\AI\ScriptPipeline\ScriptAgentsGate;
use App\Services\Automation\Triage\TriageGate;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Global autopilot preflight.
 *
 * Automated execution is allowed only when the master switch, every G-02..G-06
 * feature gate, the monthly budget, and the budget circuit breaker all pass.
 * The default state is blocked; enabling one pillar is never sufficient.
 */
class AutopilotGateService
{
    /**
     * @return array{
     *     allowed: bool,
     *     gates: array<string, bool>,
     *     blocked: list<string>,
     *     budget_remaining_micros: int,
     *     breaker_state: string
     * }
     */
    public function snapshot(): array
    {
        $gates = [
            'g01_automation' => (bool) config('automation.enabled', false),
            'g02_triage' => app(TriageGate::class)->enabled(),
            'g03_script_agents' => app(ScriptAgentsGate::class)->enabled(),
            'g04_captions' => $this->configAndFlag(
                'automation.captions.enabled',
                'automation.captions.feature_flag',
                'captions.enabled',
            ),
            'g05_assets' => $this->configAndFlag(
                'automation.assets.enabled',
                'automation.assets.feature_flag',
                'assets.enabled',
            ),
            'g06_formats' => $this->configAndFlag(
                'automation.formats.enabled',
                'automation.formats.feature_flag',
                'formats.enabled',
            ),
        ];

        $blocked = [];

        foreach ($gates as $gate => $enabled) {
            if (! $enabled) {
                $blocked[] = $gate;
            }
        }

        $budget = $this->budgetSnapshot();
        $remaining = (int) ($budget['remaining_micros'] ?? 0);
        $breaker = (string) ($budget['breaker_state'] ?? CircuitState::Open->value);

        if ($remaining <= 0) {
            $blocked[] = 'budget_exhausted';
        }

        if ($breaker !== CircuitState::Closed->value) {
            $blocked[] = 'circuit_breaker_'.$breaker;
        }

        return [
            'allowed' => $blocked === [],
            'gates' => $gates,
            'blocked' => $blocked,
            'budget_remaining_micros' => $remaining,
            'breaker_state' => $breaker,
        ];
    }

    public function evaluate(): bool
    {
        return $this->snapshot()['allowed'];
    }

    public function assertAllowed(): void
    {
        $snapshot = $this->snapshot();

        if (! $snapshot['allowed']) {
            throw new RuntimeException(
                'Autopilot gate blocked: '.implode(', ', $snapshot['blocked']).'.',
            );
        }
    }

    public function assertDisabled(): void
    {
        if ($this->evaluate()) {
            throw new RuntimeException('Autopilot gate is enabled; automated execution is not blocked.');
        }
    }

    private function configAndFlag(string $configKey, string $flagConfigKey, string $defaultFlag): bool
    {
        if (! (bool) config($configKey, false)) {
            return false;
        }

        if (! Schema::hasTable('feature_flags')) {
            return true;
        }

        $flag = trim((string) config($flagConfigKey, $defaultFlag));

        return app(FeatureFlagService::class)->enabled($flag !== '' ? $flag : $defaultFlag, false);
    }

    /**
     * Read-only budget snapshot. A missing budget table fails closed.
     *
     * @return array<string, mixed>
     */
    private function budgetSnapshot(): array
    {
        if (! Schema::hasTable('budget_periods')) {
            return [
                'remaining_micros' => 0,
                'breaker_state' => CircuitState::Open->value,
            ];
        }

        return app(BudgetGuard::class)->snapshot();
    }
}
