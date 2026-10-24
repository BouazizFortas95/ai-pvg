<?php

namespace App\Contracts;

use App\Services\Automation\AutomationContext;
use App\Services\Automation\StageResult;

interface AutomationStageInterface
{
    public function run(AutomationContext $context): StageResult;

    public function isRetryable(): bool;

    public function costEstimate(AutomationContext $context): int;
}
