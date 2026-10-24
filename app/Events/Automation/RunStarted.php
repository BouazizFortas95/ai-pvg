<?php

namespace App\Events\Automation;

use App\Models\AutomationRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RunStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public AutomationRun $run) {}
}
