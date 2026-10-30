<?php

namespace App\Services\Rendering;

use App\Enums\AiUsagePurpose;
use App\Exceptions\Rendering\HyperFramesDisabledException;
use App\Exceptions\Rendering\HyperFramesLintException;
use App\Exceptions\Rendering\HyperFramesUnavailableException;
use App\Models\VideoScript;
use App\Services\AI\AIManager;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\CircuitBreaker;
use App\Services\Automation\DryRunContext;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Optional Mode B: an LLM edits the composition HTML.
 *
 * Safety contract:
 * - disabled unless BOTH config and the render.hyperframes.agent flag are on;
 * - dry-run does zero provider calls and writes zero ledger rows;
 * - every paid call is budget-reserved and settled into ai_usage_ledger;
 * - the generated HTML is rejected if it contains network or nondeterminism
 *   primitives, then gated by `hyperframes lint` and `hyperframes check`.
 * - a human Co-Pilot review must be recorded before a real render.
 */
class HyperFramesAgentAuthor
{
    public function __construct(
        protected HyperFramesCompositionBuilder $builder,
        protected ProcessRunner $runner,
        protected BudgetGuard $budget,
        protected CircuitBreaker $breaker,
        protected DryRunContext $dryRun,
    ) {}

    public function enabled(): bool
    {
        if (! (bool) config('automation.render.hyperframes.agent.enabled', false)) {
            return false;
        }

        return HyperFramesRuntime::flagEnabled(
            (string) config('automation.render.hyperframes.agent.feature_flag', 'render.hyperframes.agent')
        );
    }

    /**
     * @param  array{preset?: string, theme?: string, run_id?: string, fps?: int}  $options
     * @return array{html: string, projectDir: string, compositionId: string, runId: string, manifest: array<string, mixed>}
     */
    public function authorOrFallback(VideoScript $script, array $options = []): array
    {
        if (! $this->enabled() || ! (bool) config('automation.render.hyperframes.agent.use_ai', false)) {
            return $this->builder->buildFromScript($script, $options);
        }

        if ($this->dryRun->isDryRun(null)) {
            $built = $this->builder->buildFromScript($script, $options);
            $built['manifest']['mode'] = 'template_dry_run';

            File::put(
                $built['projectDir'].'/manifest.json',
                json_encode($built['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );

            return $built;
        }

        if (! (bool) config('automation.render.hyperframes.agent.copilot_reviewed', false)) {
            throw new HyperFramesDisabledException(
                'Agent-authored HyperFrames output requires a recorded Co-Pilot review '
                .'(AUTOMATION_HYPERFRAMES_AGENT_COPILOT_REVIEWED=true) before rendering.'
            );
        }

        if (! $this->breaker->canAttempt('ai')) {
            throw new HyperFramesUnavailableException('AI circuit breaker is open; agent authorship is unavailable.');
        }

        $estimatedMicros = max(0, (int) config('automation.render.hyperframes.agent.estimated_call_micros', 20_000));
        $reservation = $this->budget->authorize($estimatedMicros);

        try {
            $provider = $this->dryRun->providerFor('ai');
            $manager = $this->managerFor($provider);
            $model = trim((string) config('automation.render.hyperframes.agent.model', ''));
            $optionsForAi = [
                'temperature' => (float) config('automation.render.hyperframes.agent.temperature', 0.2),
                'max_tokens' => (int) config('automation.render.hyperframes.agent.max_tokens', 4000),
                'system_prompt' => $this->systemPrompt(),
            ];

            if ($model !== '') {
                $optionsForAi['model'] = $model;
            }

            $html = $manager->generate($this->userPrompt($script, $options), $optionsForAi);
        } catch (Throwable $exception) {
            $this->budget->release($reservation);

            throw $exception;
        }

        $this->assertSafeHtml($html);

        $built = $this->builder->writeAgentComposition($script, $html, $options);

        $lint = $this->runner->run(
            [...HyperFramesRuntime::commandPrefix(), 'lint'],
            $built['projectDir'],
            HyperFramesRuntime::environment(),
            180,
        );

        if (! $lint->successful) {
            $this->budget->release($reservation);

            throw new HyperFramesLintException('Agent composition failed lint: '.$this->tail($lint->errorOutput ?: $lint->output));
        }

        $check = $this->runner->run(
            [...HyperFramesRuntime::commandPrefix(), 'check', '--json'],
            $built['projectDir'],
            HyperFramesRuntime::environment(),
            300,
        );

        if (! $check->successful) {
            $this->budget->release($reservation);

            throw new HyperFramesLintException('Agent composition failed check: '.$this->tail($check->errorOutput ?: $check->output));
        }

        $tokensIn = max(1, (int) ceil(str_word_count($this->userPrompt($script, $options)) * 1.3));
        $tokensOut = max(1, (int) ceil(str_word_count($html) * 1.3));

        $this->budget->settle($reservation, $estimatedMicros, [
            'provider' => $manager->getProviderName(),
            'model' => $model !== '' ? $model : 'provider-default',
            'purpose' => AiUsagePurpose::Script,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
        ]);

        $this->breaker->recordSuccess('ai');

        return $built;
    }

    /**
     * Reject anything that would break deterministic, offline rendering.
     */
    public function assertSafeHtml(string $html): void
    {
        if (! str_contains($html, 'data-composition-id')) {
            throw new HyperFramesLintException('Agent output is missing data-composition-id.');
        }

        if (! str_contains($html, 'window.__timelines')) {
            throw new HyperFramesLintException('Agent output is missing a registered paused timeline.');
        }

        $forbidden = ['http://', 'https://', 'Date.now', 'Math.random', 'setTimeout', 'setInterval', 'requestAnimationFrame'];

        foreach ($forbidden as $needle) {
            if (str_contains($html, $needle)) {
                throw new HyperFramesLintException('Agent output contains a forbidden nondeterministic/network primitive: '.$needle);
            }
        }
    }

    /**
     * Overridable seam: tests replace this with a mocked AIManager so no
     * provider/network call is made.
     */
    protected function managerFor(string $provider): AIManager
    {
        return new AIManager($provider);
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You author a single self-contained HyperFrames HTML composition for a vertical short video.
Rules that must never be broken:
- Root element has data-composition-id, data-start="0", data-duration, data-width, data-height, data-fps.
- Every timed element is class="clip" with data-start and data-duration (seconds).
- Build one paused GSAP timeline and register it: window.__timelines["<composition-id>"] = tl;
- Load GSAP only from the local relative path assets/vendor/gsap.min.js.
- No network URLs, no Date.now(), no Math.random(), no timers, no requestAnimationFrame, no CSS keyframe animation.
- Animate transform and opacity only, on children of clips, with gsap.fromTo and explicit end holds.
- Respect 9:16 safe areas: no text in the top 168px or bottom 590px.
- Copy must come only from the supplied script; never invent facts or numbers.
Return only the complete HTML document, no markdown fences.
PROMPT;
    }

    /**
     * @param  array{preset?: string, theme?: string}  $options
     */
    protected function userPrompt(VideoScript $script, array $options): string
    {
        $scenes = json_encode($script->scenes ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return implode("\n", [
            'Title: '.$script->title,
            'Language: '.($script->language ?? 'en'),
            'Duration: '.($script->target_duration ?? 30).' seconds',
            'Dimensions: '.((($options['preset'] ?? '9:16') === '16:9') ? '1920x1080' : '1080x1920'),
            'Theme: '.($options['theme'] ?? 'cyber'),
            'Scenes JSON: '.$scenes,
            'Write the full HTML composition now.',
        ]);
    }

    protected function tail(string $value, int $limit = 2000): string
    {
        $value = trim($value);

        return $value === '' ? '(no output)' : substr($value, -$limit);
    }
}
