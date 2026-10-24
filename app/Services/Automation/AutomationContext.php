<?php

namespace App\Services\Automation;

use App\Models\AutomationRun;

final readonly class AutomationContext
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public AutomationRun $run,
        public string $stage,
        public bool $dryRun,
        public array $payload = [],
    ) {}
}
