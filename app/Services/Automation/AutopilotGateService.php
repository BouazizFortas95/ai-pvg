<?php

namespace App\Services\Automation;

use RuntimeException;

class AutopilotGateService
{
    public function evaluate(): bool
    {
        $gates = [
            'automation_enabled' => config('automation.enabled', false),
            'script_agents' => config('automation.script_agents.enabled', false),
            'captions' => config('automation.captions.enabled', false),
            'assets' => config('automation.assets.enabled', false),
            'triage' => config('automation.triage.enabled', false),
        ];
        // Hard gate: no automated execution unless explicitly enabled per pillar
        if (array_filter($gates) === []) {
            return false; // safe default; requires explicit opt-in per feature
        }
        return true;
    }

    public function assertDisabled(): void
    {
        if ($this->evaluate()) {
            throw new RuntimeException('Autopilot gate blocked: not all features explicitly enabled.');
        }
    }
}
