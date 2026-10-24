<?php

namespace App\Services\Automation\Greenlight;

use App\Enums\TriageBand;
use App\Models\ApprovalGate;
use App\Models\AutomationRun;
use App\Models\VideoScript;

final readonly class GreenlightResult
{
    public const ACTION_AUTO_GREENLIGHT = 'auto_greenlight';

    public const ACTION_REVIEW_GATE = 'review_gate';

    public const ACTION_NONE = 'none';

    /**
     * @param  array<string, mixed>|null  $plannedScript
     */
    public function __construct(
        public TriageBand $band,
        public string $action,
        public float $score,
        public ?AutomationRun $run = null,
        public ?VideoScript $script = null,
        public ?ApprovalGate $gate = null,
        public ?array $plannedScript = null,
        public string $message = '',
    ) {}

    public function isPlanned(): bool
    {
        return $this->plannedScript !== null && $this->script === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'band' => $this->band->value,
            'action' => $this->action,
            'score' => $this->score,
            'automation_run_id' => $this->run?->getKey(),
            'video_script_id' => $this->script?->getKey(),
            'approval_gate_id' => $this->gate?->getKey(),
            'dry_run' => $this->isPlanned(),
            'message' => $this->message,
        ];
    }
}
