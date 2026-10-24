<?php

namespace App\Services\AI\ScriptPipeline;

use App\Exceptions\Automation\ScriptAgentsDisabledException;
use App\Services\Automation\FeatureFlagService;
use Illuminate\Support\Facades\Schema;

/**
 * G-03 requires both controls: the config switch and the database feature
 * flag. If the flag table is unavailable, only the config switch applies.
 */
class ScriptAgentsGate
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    public function enabled(): bool
    {
        if (! (bool) config('automation.script_agents.enabled', false)) {
            return false;
        }

        if (! Schema::hasTable('feature_flags')) {
            return true;
        }

        return $this->flags->enabled($this->flagKey(), false);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw ScriptAgentsDisabledException::disabled();
        }
    }

    public function flagKey(): string
    {
        $key = trim((string) config('automation.script_agents.feature_flag', 'script_agents.enabled'));

        return $key !== '' ? $key : 'script_agents.enabled';
    }
}
