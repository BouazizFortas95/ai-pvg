<?php

namespace App\Exceptions\Automation;

use RuntimeException;

class TriageDisabledException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('Triage is disabled. Enable automation.triage.enabled or the triage feature flag first.');
    }
}
