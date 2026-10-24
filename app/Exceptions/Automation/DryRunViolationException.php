<?php

namespace App\Exceptions\Automation;

use RuntimeException;

class DryRunViolationException extends RuntimeException
{
    public static function forAction(string $action): self
    {
        return new self("Action [{$action}] is blocked while automation dry-run is active.");
    }
}
