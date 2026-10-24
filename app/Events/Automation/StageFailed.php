<?php

namespace App\Events\Automation;

use App\Models\AutomationStageRun;
use App\Services\Automation\StageResult;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StageFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public AutomationStageRun $stageRun,
        public StageResult $result,
    ) {}
}
