<?php

namespace App\Events\Automation;

use App\Models\ApprovalGate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalDecided
{
    use Dispatchable, SerializesModels;

    public function __construct(public ApprovalGate $gate) {}
}
