<?php

namespace App\Exceptions\Automation;

use RuntimeException;

class PublishingDisabledException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('Publishing is disabled. Enable automation.publishing.enabled and the publishing feature flag first.');
    }
}
