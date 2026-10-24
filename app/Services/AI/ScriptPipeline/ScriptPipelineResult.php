<?php

namespace App\Services\AI\ScriptPipeline;

use App\Models\ApprovalGate;
use App\Models\AutomationRun;
use App\Models\ScriptFactCheck;
use App\Models\ScriptVariant;
use App\Models\VideoScript;

final readonly class ScriptPipelineResult
{
    /**
     * @param  list<ScriptVariant>  $variants
     * @param  list<ScriptFactCheck>  $factChecks
     * @param  array<string, mixed>|null  $plannedVariants
     * @param  array<string, mixed>|null  $plannedScenes
     * @param  array<string, mixed>|null  $plannedFactChecks
     */
    public function __construct(
        public AutomationRun $run,
        public ?VideoScript $script = null,
        public array $variants = [],
        public array $factChecks = [],
        public ?ApprovalGate $gate = null,
        public bool $blocked = false,
        public string $message = '',
        public ?array $plannedVariants = null,
        public ?array $plannedScenes = null,
        public ?array $plannedFactChecks = null,
        public ?float $criticScore = null,
    ) {}

    public function isDryRun(): bool
    {
        return (bool) $this->run->dry_run;
    }

    public function hasBlockingClaims(): bool
    {
        foreach ($this->factChecks as $check) {
            if ($check->blocksApproval()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'automation_run_id' => (int) $this->run->getKey(),
            'video_script_id' => $this->script?->getKey(),
            'variant_ids' => array_map(fn (ScriptVariant $variant): int => (int) $variant->getKey(), $this->variants),
            'variant_count' => count($this->variants),
            'fact_check_ids' => array_map(fn (ScriptFactCheck $check): int => (int) $check->getKey(), $this->factChecks),
            'approval_gate_id' => $this->gate?->getKey(),
            'blocked' => $this->blocked,
            'critic_score' => $this->criticScore,
            'dry_run' => $this->isDryRun(),
            'message' => $this->message,
        ];
    }
}
