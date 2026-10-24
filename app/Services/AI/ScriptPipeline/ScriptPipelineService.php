<?php

namespace App\Services\AI\ScriptPipeline;

use App\Enums\ApprovalGateType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTrigger;
use App\Enums\ScriptVariantType;
use App\Enums\VideoScriptStatus;
use App\Models\ApprovalGate;
use App\Models\AutomationRun;
use App\Models\News;
use App\Models\ScriptFactCheck;
use App\Models\ScriptVariant;
use App\Models\StoryCluster;
use App\Models\VideoScript;
use App\Services\Automation\ApprovalService;
use App\Services\Automation\AutomationOrchestrator;
use App\Services\Automation\DryRunContext;
use App\Services\Automation\Stages\ComposeScriptStage;
use App\Services\Automation\Stages\FactCheckScriptStage;
use App\Services\Automation\Stages\GenerateScriptVariantsStage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ScriptPipelineService
{
    public function __construct(
        private readonly ScriptAgentsGate $gate,
        private readonly AutomationOrchestrator $orchestrator,
        private readonly DryRunContext $dryRun,
        private readonly ApprovalService $approvals,
        private readonly ScriptSourceFactory $sources,
        private readonly HookSpecialistAgent $hooks,
        private readonly NarrativeArchitectAgent $narrator,
        private readonly DirectiveValidator $validator,
        private readonly FactCheckerAgent $factChecker,
    ) {}

    public function runPipeline(StoryCluster|News $subject, ?int $hookIndex = null): ScriptPipelineResult
    {
        $this->gate->assertEnabled();
        $source = $this->sources->fromModel($subject);
        $run = $this->orchestrator->createRun(
            pipeline: 'script_agents.pipeline',
            subject: $subject,
            trigger: AutomationTrigger::Manual,
            dryRun: $this->dryRun->isDryRun(),
            activeWindow: $source->windowKey,
            meta: [
                'source_key' => $source->sourceKey,
                'source_news_ids' => $source->newsIds(),
                'prompt_version' => (string) config('automation.script_agents.prompt_version', 'g03-v1'),
            ],
        );

        if ($run->status === AutomationRunStatus::Completed) {
            return $this->resultFromRun($run, $source);
        }

        if ($run->isTerminal()) {
            return new ScriptPipelineResult(
                run: $run,
                blocked: true,
                message: 'The existing script-agent run is terminal and cannot be resumed.',
            );
        }

        $stages = [
            ['generate_variants', GenerateScriptVariantsStage::class, []],
            ['compose_script', ComposeScriptStage::class, $hookIndex !== null ? ['hook_index' => $hookIndex] : []],
            ['fact_check_script', FactCheckScriptStage::class, []],
        ];

        foreach ($stages as [$name, $class, $payload]) {
            $result = $this->orchestrator->executeStage($run, $name, app($class), $payload);

            if (! $result->successful()) {
                return new ScriptPipelineResult(
                    run: $run->fresh(),
                    blocked: true,
                    message: $result->errorMessage ?? "Script agent stage [{$name}] failed.",
                );
            }
        }

        $run->refresh();

        if (! $run->isTerminal()) {
            $run = $this->orchestrator->completeRun($run);
        }

        return $this->resultFromRun($run, $source);
    }

    public function generateVariants(ScriptSource $source, AutomationRun $run): ScriptPipelineResult
    {
        $this->gate->assertEnabled();
        $script = $this->resolveScript($run, $source);

        if ($script !== null && $script->status === VideoScriptStatus::Approved) {
            return $this->protectedResult($run, $script, 'Approved scripts are protected; the automated run did not overwrite them.');
        }

        if ($this->dryRun->isDryRun($run)) {
            $hooks = $this->hooks->generate($source, $run);
            $planned = $this->dryRun->syntheticOutput('g03_generate_variants', [
                'source' => $source->toArray(),
                'variants' => $hooks,
            ]);
            $this->mergeRunMeta($run, [
                'planned_variants' => $planned,
                'selected_hook_index' => 1,
                'last_message' => 'Dry-run: three hook variants were planned; no production rows were written.',
            ]);

            return new ScriptPipelineResult(
                run: $run->fresh(),
                plannedVariants: $planned,
                message: 'Dry-run: three hook variants were planned; no production rows were written.',
            );
        }

        $hookVariants = $script !== null
            ? $script->loadMissing('variants')->variants
                ->where('variant_type', ScriptVariantType::Hook)
                ->sortBy('hook_index')
                ->values()
            : collect();

        if ($hookVariants->count() === 3) {
            $this->mergeRunMeta($run, [
                'video_script_id' => (int) $script->getKey(),
                'last_message' => 'Existing three hook variants returned; no duplicates were created.',
            ]);

            return new ScriptPipelineResult(
                run: $run->fresh(),
                script: $script,
                variants: $hookVariants->all(),
                message: 'Existing three hook variants returned; no duplicates were created.',
            );
        }

        $hooks = $this->hooks->generate($source, $run);

        if (count($hooks) !== 3) {
            throw new RuntimeException('The hook specialist must return exactly three grounded hooks.');
        }

        $distinct = array_unique(array_map(
            fn (array $hook): string => mb_strtolower(trim($hook['content'])),
            $hooks,
        ));

        if (count($distinct) !== 3) {
            throw new RuntimeException('The hook specialist returned duplicate hooks.');
        }

        $script ??= $this->createDraftScript($source, $run);
        $selectedIndex = (int) ($hookVariants->firstWhere('selected', true)?->hook_index ?? 1);
        $promptVersion = (string) config('automation.script_agents.prompt_version', 'g03-v1');

        DB::transaction(function () use ($script, $source, $hooks, $selectedIndex, $promptVersion, $run): void {
            $script->variants()->where('variant_type', ScriptVariantType::Hook->value)->update(['selected' => false]);

            foreach (array_values($hooks) as $index => $hook) {
                $hookIndex = $index + 1;

                ScriptVariant::updateOrCreate(
                    [
                        'video_script_id' => $script->getKey(),
                        'variant_key' => $source->windowKey.':hook:'.$hookIndex,
                    ],
                    [
                        'variant_type' => ScriptVariantType::Hook,
                        'hook_index' => $hookIndex,
                        'hook_type' => $hook['hook_type'],
                        'content' => $hook['content'],
                        'retention_rationale' => $hook['retention_rationale'],
                        'score' => $hook['score'],
                        'selected' => $hookIndex === $selectedIndex,
                        'source_news_ids' => $hook['source_news_ids'],
                        'prompt_version' => $promptVersion,
                        'model_version' => $hook['model'],
                        'meta' => [
                            'provider' => $hook['provider'],
                            'source_key' => $source->sourceKey,
                        ],
                    ],
                );
            }

            $this->mergeRunMeta($run, [
                'video_script_id' => (int) $script->getKey(),
                'selected_hook_index' => $selectedIndex,
                'last_message' => 'Three grounded hook variants generated.',
            ]);
        });

        $script->refresh()->load('variants');
        $variants = $script->variants
            ->where('variant_type', ScriptVariantType::Hook)
            ->sortBy('hook_index')
            ->values();

        return new ScriptPipelineResult(
            run: $run->fresh(),
            script: $script,
            variants: $variants->all(),
            message: 'Three grounded hook variants generated.',
        );
    }

    public function composeScript(ScriptSource $source, AutomationRun $run, ?int $hookIndex = null): ScriptPipelineResult
    {
        $this->gate->assertEnabled();
        $script = $this->resolveScript($run, $source);

        if ($script !== null && $script->status === VideoScriptStatus::Approved) {
            return $this->protectedResult($run, $script, 'Approved scripts are protected; the automated run did not overwrite them.');
        }

        if ($this->dryRun->isDryRun($run)) {
            $hooks = $this->hooks->generate($source, $run);
            $index = max(1, min(3, $hookIndex ?? (int) ($run->meta['selected_hook_index'] ?? 1)));
            $hook = $hooks[$index - 1] ?? $hooks[0];
            $scenes = $this->narrator->compose($source, (string) $hook['content']);
            $this->validator->validate($scenes, $source->targetDuration, $source->newsIds());
            $planned = $this->dryRun->syntheticOutput('g03_compose_script', [
                'source' => $source->toArray(),
                'selected_hook_index' => $index,
                'selected_hook' => $hook['content'],
                'scenes' => $scenes,
                'estimated_duration' => array_sum(array_column($scenes, 'duration')),
            ]);
            $this->mergeRunMeta($run, [
                'selected_hook_index' => $index,
                'planned_scenes' => $planned,
                'last_message' => 'Dry-run: scene directives were planned and validated.',
            ]);

            return new ScriptPipelineResult(
                run: $run->fresh(),
                plannedScenes: $planned,
                message: 'Dry-run: scene directives were planned and validated.',
            );
        }

        if ($script === null) {
            $generated = $this->generateVariants($source, $run);

            if ($generated->blocked || $generated->script === null) {
                return $generated;
            }

            $script = $generated->script;
        }

        $script->loadMissing('variants');
        $variants = $script->variants
            ->where('variant_type', ScriptVariantType::Hook)
            ->sortBy('hook_index')
            ->values();

        if ($variants->isEmpty()) {
            $generated = $this->generateVariants($source, $run);
            $script = $generated->script;

            if ($script === null) {
                return $generated;
            }

            $script->loadMissing('variants');
            $variants = $script->variants
                ->where('variant_type', ScriptVariantType::Hook)
                ->sortBy('hook_index')
                ->values();
        }

        $selectedIndex = max(1, min(3, $hookIndex
            ?? (int) ($variants->firstWhere('selected', true)?->hook_index ?? 1)));
        $variant = $variants->firstWhere('hook_index', $selectedIndex) ?? $variants->first();

        if (! $variant instanceof ScriptVariant) {
            throw new RuntimeException('No hook variant is available to compose the script.');
        }

        $scenes = $this->narrator->compose($source, (string) $variant->content);
        $this->validator->validate($scenes, $source->targetDuration, $source->newsIds());
        $narration = implode(' ', array_map(
            fn (array $scene): string => (string) ($scene['narration'] ?? ''),
            $scenes,
        ));
        $estimated = (int) array_sum(array_column($scenes, 'duration'));

        $updated = DB::transaction(function () use ($script, $variant, $scenes, $narration, $estimated, $source, $run, $selectedIndex): bool {
            $locked = VideoScript::query()->lockForUpdate()->findOrFail($script->getKey());

            if ($locked->status === VideoScriptStatus::Approved) {
                return false;
            }

            $locked->forceFill([
                'title' => $source->title,
                'language' => $source->language,
                'target_duration' => $source->targetDuration,
                'estimated_duration' => $estimated,
                'narration' => $narration,
                'scenes' => $scenes,
                'status' => VideoScriptStatus::Draft,
                'ai_provider' => 'deterministic-local',
                'ai_model' => 'g03-narrative-v1',
                'generated_at' => now(),
                'error_message' => null,
            ])->save();

            $locked->variants()->update(['selected' => false]);
            $locked->variants()->whereKey($variant->getKey())->update(['selected' => true]);
            $variant->forceFill(['selected' => true])->syncOriginalAttribute('selected');

            $this->mergeRunMeta($run, [
                'video_script_id' => (int) $locked->getKey(),
                'selected_hook_index' => $selectedIndex,
                'selected_variant_id' => (int) $variant->getKey(),
                'last_message' => 'Scene directives composed and validated.',
            ]);

            return true;
        });

        if (! $updated) {
            return $this->protectedResult($run, $script->fresh(), 'Approved scripts are protected; the automated run did not overwrite them.');
        }

        return new ScriptPipelineResult(
            run: $run->fresh(),
            script: $script->fresh(['variants']),
            variants: $script->variants()->orderBy('hook_index')->get()->all(),
            message: 'Scene directives composed and validated.',
        );
    }

    public function factCheckScript(ScriptSource $source, AutomationRun $run): ScriptPipelineResult
    {
        $this->gate->assertEnabled();
        $script = $this->resolveScript($run, $source);

        if ($script !== null && $script->status === VideoScriptStatus::Approved) {
            return $this->protectedResult($run, $script, 'Approved scripts are protected; fact checks did not overwrite them.');
        }

        if ($this->dryRun->isDryRun($run)) {
            $hooks = $this->hooks->generate($source, $run);
            $index = max(1, min(3, (int) ($run->meta['selected_hook_index'] ?? 1)));
            $hook = $hooks[$index - 1] ?? $hooks[0];
            $scenes = $this->narrator->compose($source, (string) $hook['content']);
            $checks = $this->factChecker->check(
                $scenes,
                $source->news,
                $source->factSheet,
                [['claim' => (string) $hook['content'], 'source_news_ids' => $hook['source_news_ids']]],
            );
            $serialized = $this->serializeChecks($checks);
            $blocked = $this->checksBlock($checks);
            $critic = $this->averageCriticScore($checks);
            $planned = $this->dryRun->syntheticOutput('g03_fact_check', [
                'source' => $source->toArray(),
                'checks' => $serialized,
                'blocked' => $blocked,
                'critic_score' => $critic,
            ]);
            $this->mergeRunMeta($run, [
                'planned_fact_checks' => $planned,
                'critic_score' => $critic,
                'fact_check_blocked' => $blocked,
                'last_message' => 'Dry-run: fact checks were planned; no production rows were written.',
            ]);

            return new ScriptPipelineResult(
                run: $run->fresh(),
                blocked: $blocked,
                plannedFactChecks: $planned,
                criticScore: $critic,
                message: 'Dry-run: fact checks were planned; no production rows were written.',
            );
        }

        if ($script === null) {
            throw new RuntimeException('No video script exists for fact checking.');
        }

        $script->loadMissing('variants');

        if (! is_array($script->scenes) || $script->scenes === []) {
            $composed = $this->composeScript($source, $run);

            if ($composed->blocked || $composed->script === null) {
                return $composed;
            }

            $script = $composed->script;
        }

        $variant = $script->variants
            ->where('variant_type', ScriptVariantType::Hook)
            ->firstWhere('selected', true)
            ?? $script->variants->where('variant_type', ScriptVariantType::Hook)->sortBy('hook_index')->first();

        if (! $variant instanceof ScriptVariant) {
            throw new RuntimeException('No selected hook variant exists for fact checking.');
        }

        $scenes = $script->scenes ?? [];
        $checks = $this->factChecker->check(
            $scenes,
            $source->news,
            $source->factSheet,
            [['claim' => (string) $variant->content, 'source_news_ids' => $variant->source_news_ids ?? []]],
        );

        if ($checks === []) {
            throw new RuntimeException('Fact checking produced no claims to verify.');
        }

        $blocked = $this->checksBlock($checks);
        $critic = $this->averageCriticScore($checks);
        $claimHashes = array_column($checks, 'claim_hash');

        $updated = DB::transaction(function () use ($script, $variant, $checks, $claimHashes, $blocked, $critic, $run): bool {
            $locked = VideoScript::query()->lockForUpdate()->findOrFail($script->getKey());

            if ($locked->status === VideoScriptStatus::Approved) {
                return false;
            }

            $locked->factChecks()
                ->whereNotIn('claim_hash', $claimHashes)
                ->delete();

            foreach ($checks as $check) {
                ScriptFactCheck::updateOrCreate(
                    [
                        'video_script_id' => $locked->getKey(),
                        'claim_hash' => $check['claim_hash'],
                    ],
                    [
                        'script_variant_id' => $variant->getKey(),
                        'claim' => $check['claim'],
                        'verdict' => $check['verdict'],
                        'source_news_ids' => $check['source_news_ids'],
                        'evidence' => $check['evidence'],
                        'critic_score' => $check['critic_score'],
                        'checker_version' => (string) config('automation.script_agents.prompt_version', 'g03-v1'),
                        'meta' => [
                            'coverage' => $check['coverage'],
                            'reason' => $check['reason'],
                            'evidence_news_id' => $check['evidence_news_id'],
                        ],
                    ],
                );
            }

            $locked->forceFill([
                'status' => $blocked ? VideoScriptStatus::Draft : VideoScriptStatus::Generated,
                'error_message' => null,
            ])->save();

            $this->mergeRunMeta($run, [
                'video_script_id' => (int) $locked->getKey(),
                'selected_variant_id' => (int) $variant->getKey(),
                'critic_score' => $critic,
                'fact_check_blocked' => $blocked,
                'last_message' => $blocked
                    ? 'Fact check found unsupported or uncertain claims; script held for human review.'
                    : 'All checked claims are grounded; script is ready for the normal human approval step.',
            ]);

            return true;
        });

        if (! $updated) {
            return $this->protectedResult($run, $script->fresh(), 'Approved scripts are protected; fact checks did not overwrite them.');
        }

        $gate = null;

        if ($blocked) {
            $gate = $this->approvals->request(
                subject: $variant,
                type: ApprovalGateType::ScriptFactCheck,
                meta: [
                    'fact_check_failed' => true,
                    'video_script_id' => (int) $script->getKey(),
                    'variant_key' => $variant->variant_key,
                    'critic_score' => $critic,
                    'blocked_claims' => array_values(array_map(
                        fn (array $check): string => $check['claim'],
                        array_filter($checks, fn (array $check): bool => $check['verdict']->blocksApproval()),
                    )),
                    'source_news_ids' => $source->newsIds(),
                    'dry_run' => false,
                ],
            );
        }

        $script->refresh()->load('factChecks');

        return new ScriptPipelineResult(
            run: $run->fresh(),
            script: $script,
            variants: $script->variants()->orderBy('hook_index')->get()->all(),
            factChecks: $script->factChecks->all(),
            gate: $gate,
            blocked: $blocked,
            criticScore: $critic,
            message: $blocked
                ? 'Fact check found unsupported or uncertain claims; script held for human review.'
                : 'All checked claims are grounded; script is ready for the normal human approval step.',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return list<array<string, mixed>>
     */
    private function serializeChecks(array $checks): array
    {
        return array_map(fn (array $check): array => [
            ...$check,
            'verdict' => $check['verdict']->value,
        ], $checks);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    private function checksBlock(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['verdict']->blocksApproval()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     */
    private function averageCriticScore(array $checks): float
    {
        if ($checks === []) {
            return 0.0;
        }

        return round(array_sum(array_column($checks, 'critic_score')) / count($checks), 2);
    }

    private function resolveScript(AutomationRun $run, ScriptSource $source): ?VideoScript
    {
        $meta = $run->meta ?? [];
        $scriptId = isset($meta['video_script_id']) ? (int) $meta['video_script_id'] : null;

        if ($scriptId !== null) {
            $script = VideoScript::query()->find($scriptId);

            if ($script !== null) {
                return $script;
            }
        }

        if (str_starts_with($source->sourceKey, 'cluster-')) {
            $clusterId = (int) substr($source->sourceKey, strlen('cluster-'));
            $runs = AutomationRun::query()
                ->where('pipeline', 'triage.auto_greenlight')
                ->where('subject_type', (new StoryCluster)->getMorphClass())
                ->where('subject_id', $clusterId)
                ->latest('id')
                ->get();

            foreach ($runs as $greenlightRun) {
                $greenlightMeta = $greenlightRun->meta ?? [];
                $greenlightScriptId = isset($greenlightMeta['video_script_id'])
                    ? (int) $greenlightMeta['video_script_id']
                    : null;

                if ($greenlightScriptId === null) {
                    continue;
                }

                $script = VideoScript::query()->find($greenlightScriptId);

                if ($script !== null) {
                    $this->mergeRunMeta($run, ['video_script_id' => (int) $script->getKey()]);

                    return $script;
                }
            }
        }

        return null;
    }

    private function createDraftScript(ScriptSource $source, AutomationRun $run): VideoScript
    {
        $script = VideoScript::create([
            'news_id' => $source->primaryNewsId(),
            'title' => $source->title,
            'language' => $source->language,
            'target_duration' => $source->targetDuration,
            'estimated_duration' => null,
            'narration' => null,
            'scenes' => [],
            'status' => VideoScriptStatus::Draft,
            'ai_provider' => 'deterministic-local',
            'ai_model' => 'g03-pipeline-v1',
        ]);

        $this->mergeRunMeta($run, [
            'video_script_id' => (int) $script->getKey(),
            'script_created_at' => now()->toIso8601String(),
        ]);

        return $script;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function mergeRunMeta(AutomationRun $run, array $values): void
    {
        $run->refresh();
        $meta = $run->meta ?? [];
        $run->forceFill(['meta' => array_replace($meta, $values)])->save();
        $run->refresh();
    }

    /**
     * Record an approved-script protection event on the run without ever
     * touching the approved production script.
     */
    private function protectedResult(AutomationRun $run, ?VideoScript $script, string $message): ScriptPipelineResult
    {
        $this->mergeRunMeta($run, [
            'blocked' => true,
            'script_protected' => true,
            'last_message' => $message,
        ]);

        return new ScriptPipelineResult(
            run: $run->fresh(),
            script: $script,
            blocked: true,
            message: $message,
        );
    }

    private function resultFromRun(AutomationRun $run, ScriptSource $source): ScriptPipelineResult
    {
        $meta = $run->meta ?? [];
        $scriptId = isset($meta['video_script_id']) ? (int) $meta['video_script_id'] : null;
        $script = $scriptId !== null ? VideoScript::query()->find($scriptId) : null;
        $variants = $script?->variants()->orderBy('hook_index')->get()->all() ?? [];
        $factChecks = $script?->factChecks()->orderBy('id')->get()->all() ?? [];
        $gate = null;

        if ($variants !== []) {
            $variantIds = array_map(fn (ScriptVariant $variant): int => (int) $variant->getKey(), $variants);
            $gate = ApprovalGate::query()
                ->where('gate_type', ApprovalGateType::ScriptFactCheck->value)
                ->where('gateable_type', (new ScriptVariant)->getMorphClass())
                ->whereIn('gateable_id', $variantIds)
                ->latest('id')
                ->first();
        }

        $blocked = (bool) ($meta['blocked'] ?? $meta['fact_check_blocked'] ?? false);
        $critic = isset($meta['critic_score']) ? (float) $meta['critic_score'] : null;

        return new ScriptPipelineResult(
            run: $run,
            script: $script,
            variants: $variants,
            factChecks: $factChecks,
            gate: $gate,
            blocked: $blocked,
            message: (string) ($meta['last_message'] ?? ''),
            plannedVariants: isset($meta['planned_variants']) && is_array($meta['planned_variants'])
                ? $meta['planned_variants']
                : null,
            plannedScenes: isset($meta['planned_scenes']) && is_array($meta['planned_scenes'])
                ? $meta['planned_scenes']
                : null,
            plannedFactChecks: isset($meta['planned_fact_checks']) && is_array($meta['planned_fact_checks'])
                ? $meta['planned_fact_checks']
                : null,
            criticScore: $critic,
        );
    }
}
