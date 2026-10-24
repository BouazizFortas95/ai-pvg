<?php

namespace App\Services\Automation;

use App\Exceptions\Automation\DryRunViolationException;
use App\Models\AutomationRun;

class DryRunContext
{
    public function isDryRun(?AutomationRun $run = null): bool
    {
        if ($run !== null && $run->dry_run) {
            return true;
        }

        return (bool) config('automation.dry_run', true);
    }

    public function allowsPaidCalls(?AutomationRun $run = null): bool
    {
        return ! $this->isDryRun($run);
    }

    /**
     * Resolve the provider that a stage is allowed to use. In dry-run mode
     * AI work falls back to the configured local provider and TTS uses the
     * configured local/mock provider.
     */
    public function providerFor(string $kind, ?AutomationRun $run = null): string
    {
        if ($this->isDryRun($run)) {
            return match ($kind) {
                'ai', 'llm' => (string) config('automation.providers.fallback', 'ollama'),
                'tts' => (string) config('automation.providers.tts', 'mock'),
                default => 'local',
            };
        }

        return match ($kind) {
            'ai', 'llm' => (string) config('automation.providers.default', 'ollama'),
            'tts' => (string) config('automation.providers.tts', 'mock'),
            default => 'local',
        };
    }

    public function assertActionAllowed(string $action, ?AutomationRun $run = null): void
    {
        if ($this->isDryRun($run)) {
            throw DryRunViolationException::forAction($action);
        }
    }

    public function canPerformExternalAction(?AutomationRun $run = null): bool
    {
        return ! $this->isDryRun($run);
    }

    /**
     * Label synthetic output so it can never be mistaken for a real result.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function syntheticOutput(string $stage, array $output = []): array
    {
        return [
            ...$output,
            '_automation' => [
                'dry_run' => true,
                'labeled' => true,
                'stage' => $stage,
                'provider' => $this->providerFor('ai'),
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     */
    public function syntheticStageResult(string $stage, array $output = []): StageResult
    {
        return StageResult::success($this->syntheticOutput($stage, $output));
    }
}
