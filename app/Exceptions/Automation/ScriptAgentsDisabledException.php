<?php

namespace App\Exceptions\Automation;

use RuntimeException;

class ScriptAgentsDisabledException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self(
            'Script agents are disabled. Enable automation.script_agents.enabled and the script_agents.enabled feature flag first.',
        );
    }
}
