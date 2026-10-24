<?php

namespace App\Services\AI\ScriptPipeline;

use App\Enums\AiUsagePurpose;
use App\Models\AutomationRun;
use App\Services\AI\AIManager;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\DryRunContext;
use Throwable;

/**
 * Optional provider bridge for the G-03 agents. It is disabled by default.
 * Every external call must reserve budget before the request and settle the
 * actual cost into ai_usage_ledger afterwards. Dry-run always returns null so
 * the deterministic local agents are used instead.
 */
class ScriptAgentAiClient
{
    public function __construct(
        private readonly BudgetGuard $budget,
        private readonly DryRunContext $dryRun,
    ) {}

    public function enabled(?AutomationRun $run = null): bool
    {
        if (! (bool) config('automation.script_agents.use_ai', false)) {
            return false;
        }

        return ! $this->dryRun->isDryRun($run);
    }

    public function complete(
        string $prompt,
        string $systemPrompt,
        AiUsagePurpose $purpose,
        ?AutomationRun $run = null,
    ): ?ScriptAgentAiResponse {
        if (! $this->enabled($run)) {
            return null;
        }

        if (! in_array($purpose, [AiUsagePurpose::Script, AiUsagePurpose::Critic], true)) {
            throw new \InvalidArgumentException('Script agents may only use Script or Critic purposes.');
        }

        $estimatedMicros = max(0, (int) config('automation.script_agents.estimated_call_micros', 10_000));
        $reservation = $this->budget->authorize($estimatedMicros, $run);

        try {
            $provider = $this->dryRun->providerFor('ai', $run);
            $manager = new AIManager($provider);
            $options = [
                'temperature' => (float) config('automation.script_agents.temperature', 0.2),
                'max_tokens' => (int) config('automation.script_agents.max_tokens', 1200),
                'system_prompt' => $systemPrompt,
            ];
            $model = trim((string) config('automation.script_agents.model', ''));

            if ($model !== '') {
                $options['model'] = $model;
            }

            $content = $manager->generate($prompt, $options);
        } catch (Throwable $exception) {
            $this->budget->release($reservation);

            throw $exception;
        }

        $tokensIn = max(1, (int) ceil(str_word_count($prompt) * 1.3));
        $tokensOut = max(1, (int) ceil(str_word_count($content) * 1.3));

        $this->budget->settle($reservation, $estimatedMicros, [
            'provider' => $manager->getProviderName(),
            'model' => $model !== '' ? $model : 'provider-default',
            'purpose' => $purpose,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ]);

        return new ScriptAgentAiResponse(
            content: $content,
            provider: $manager->getProviderName(),
            model: $model !== '' ? $model : 'provider-default',
            tokensIn: $tokensIn,
            tokensOut: $tokensOut,
            costMicros: $estimatedMicros,
        );
    }
}
