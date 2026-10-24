<?php

namespace App\Services\Automation;

use App\Enums\AutomationStageStatus;
use InvalidArgumentException;

final readonly class StageResult
{
    /**
     * @param  array<string, mixed>  $output
     */
    public function __construct(
        public AutomationStageStatus $status,
        public array $output = [],
        public int $costMicros = 0,
        public ?string $errorMessage = null,
        public bool $retryable = false,
    ) {
        if (! in_array($status, [
            AutomationStageStatus::Completed,
            AutomationStageStatus::Failed,
            AutomationStageStatus::Skipped,
        ], true)) {
            throw new InvalidArgumentException('A stage result must be completed, failed, or skipped.');
        }

        if ($costMicros < 0) {
            throw new InvalidArgumentException('Stage cost cannot be negative.');
        }
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public static function success(array $output = [], int $costMicros = 0): self
    {
        return new self(AutomationStageStatus::Completed, $output, $costMicros);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public static function failure(string $errorMessage, bool $retryable = false, int $costMicros = 0, array $output = []): self
    {
        return new self(AutomationStageStatus::Failed, $output, $costMicros, $errorMessage, $retryable);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public static function skipped(string $reason = '', array $output = []): self
    {
        return new self(
            AutomationStageStatus::Skipped,
            [...$output, 'reason' => $reason],
            0,
            $reason !== '' ? $reason : null,
        );
    }

    public function successful(): bool
    {
        return $this->status === AutomationStageStatus::Completed;
    }
}
