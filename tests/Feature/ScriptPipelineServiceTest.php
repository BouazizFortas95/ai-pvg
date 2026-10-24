<?php

namespace Tests\Feature;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Enums\AutomationRunStatus;
use App\Enums\ScriptFactCheckVerdict;
use App\Enums\VideoScriptStatus;
use App\Models\AiUsageLedgerEntry;
use App\Models\ApprovalGate;
use App\Models\AutomationRun;
use App\Models\ScriptFactCheck;
use App\Models\ScriptVariant;
use App\Models\VideoScript;
use App\Services\AI\ScriptPipeline\HookSpecialistAgent;
use App\Services\AI\ScriptPipeline\ScriptPipelineResult;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use App\Services\AI\ScriptPipeline\ScriptSourceFactory;
use App\Services\Automation\AutomationOrchestrator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsScriptFixtures;
use Tests\TestCase;

class ScriptPipelineServiceTest extends TestCase
{
    use BuildsScriptFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        $this->enableScriptAgents();
    }

    public function test_full_pipeline_generates_hooks_directives_and_supported_fact_checks(): void
    {
        $cluster = $this->makeScriptCluster();

        $result = app(ScriptPipelineService::class)->runPipeline($cluster);

        $this->assertFalse($result->blocked, $result->message);
        $this->assertSame(AutomationRunStatus::Completed, $result->run->status);
        $this->assertNotNull($result->script);

        $script = $result->script->fresh();
        $this->assertSame(VideoScriptStatus::Generated, $script->status);
        $this->assertSame(45, (int) $script->estimated_duration);
        $this->assertNotEmpty($script->scenes);

        $variants = $script->variants()->orderBy('hook_index')->get();
        $this->assertCount(3, $variants);
        $this->assertSame([1, 2, 3], $variants->pluck('hook_index')->all());
        $this->assertCount(3, $variants->pluck('content')->unique());
        $this->assertSame(1, $script->variants()->where('selected', true)->count());
        $this->assertSame('cluster-'.$cluster->getKey().':g03-v1', $result->run->active_window);

        foreach ($variants as $variant) {
            $this->assertSame('cluster-'.$cluster->getKey().':g03-v1:hook:'.$variant->hook_index, $variant->variant_key);
            $this->assertNotEmpty($variant->source_news_ids);
            $this->assertSame('deterministic-local', $variant->meta['provider'] ?? null);
            $this->assertSame('g03-v1', $variant->prompt_version);
        }

        $total = 0;

        foreach ($script->scenes as $index => $scene) {
            $this->assertSame($index + 1, $scene['order']);
            $this->assertArrayHasKey('directives', $scene);
            $this->assertSame(
                ['camera_motion', 'b_roll_query', 'highlight_text', 'sfx_cue', 'emotion_tone', 'duration_seconds'],
                array_keys($scene['directives']),
            );
            $this->assertSame($scene['duration'], $scene['directives']['duration_seconds']);
            $total += (int) $scene['duration'];
        }

        $this->assertLessThanOrEqual(5, abs($total - 45));

        $checks = $script->factChecks()->get();
        $this->assertGreaterThanOrEqual(3, $checks->count());

        foreach ($checks as $check) {
            $this->assertSame(ScriptFactCheckVerdict::Supported, $check->verdict);
            $this->assertNotEmpty($check->source_news_ids);
            $this->assertNotSame('', trim((string) $check->evidence));
        }

        $this->assertSame(0, AiUsageLedgerEntry::count());
        $this->assertSame(0, ApprovalGate::count());
        Http::assertNothingSent();
    }

    public function test_pipeline_is_idempotent_within_the_g03_window(): void
    {
        $cluster = $this->makeScriptCluster();
        $service = app(ScriptPipelineService::class);

        $first = $service->runPipeline($cluster);
        $second = $service->runPipeline($cluster);

        $this->assertSame((int) $first->run->getKey(), (int) $second->run->getKey());
        $this->assertSame((int) $first->script?->getKey(), (int) $second->script?->getKey());
        $this->assertSame(1, AutomationRun::query()->where('pipeline', 'script_agents.pipeline')->count());
        $this->assertSame(1, VideoScript::count());
        $this->assertSame(3, ScriptVariant::count());
        $this->assertSame(
            $first->script?->factChecks()->count(),
            $second->script?->factChecks()->count(),
        );
        $this->assertSame(3, $second->run->stageRuns()->count());
    }

    public function test_dry_run_returns_labeled_plans_without_production_mutations(): void
    {
        config([
            'automation.dry_run' => true,
            'automation.script_agents.use_ai' => true,
        ]);

        $cluster = $this->makeScriptCluster();
        $result = app(ScriptPipelineService::class)->runPipeline($cluster);

        $this->assertTrue($result->isDryRun());
        $this->assertNull($result->script);
        $this->assertSame([], $result->variants);
        $this->assertSame([], $result->factChecks);
        $this->assertNotNull($result->plannedVariants);
        $this->assertNotNull($result->plannedScenes);
        $this->assertNotNull($result->plannedFactChecks);
        $this->assertTrue($result->plannedVariants['_automation']['dry_run']);
        $this->assertTrue($result->plannedVariants['_automation']['labeled']);
        $this->assertCount(3, $result->plannedVariants['variants']);
        $this->assertTrue($result->plannedScenes['_automation']['labeled']);
        $this->assertTrue($result->plannedFactChecks['_automation']['labeled']);

        $this->assertSame(0, VideoScript::count());
        $this->assertSame(0, ScriptVariant::count());
        $this->assertSame(0, ScriptFactCheck::count());
        $this->assertSame(0, AiUsageLedgerEntry::count());
        $this->assertSame(0, ApprovalGate::count());
        Http::assertNothingSent();
    }

    public function test_unsupported_claims_block_approval_and_open_a_review_gate(): void
    {
        $cluster = $this->makeScriptCluster();
        $source = app(ScriptSourceFactory::class)->fromModel($cluster);
        $hooks = app(HookSpecialistAgent::class)->generate($source);
        $primaryId = $source->primaryNewsId();

        $script = VideoScript::create([
            'news_id' => $primaryId,
            'title' => $source->title,
            'language' => 'en',
            'target_duration' => 45,
            'estimated_duration' => 45,
            'narration' => 'Grounded introduction. Quantum teleportation achieved on Mars in 2027.',
            'scenes' => [
                [
                    'order' => 1,
                    'type' => 'intro',
                    'duration' => 5,
                    'headline' => 'Grounded introduction',
                    'description' => 'Intro',
                    'narration' => $hooks[0]['content'],
                    'claim' => $hooks[0]['content'],
                    'source_news_ids' => [$primaryId],
                ],
                [
                    'order' => 2,
                    'type' => 'segment',
                    'duration' => 40,
                    'headline' => 'Unsupported claim',
                    'description' => 'Segment',
                    'narration' => 'Quantum teleportation achieved on Mars in 2027.',
                    'claim' => 'Quantum teleportation achieved on Mars in 2027.',
                    'source_news_ids' => [$primaryId],
                ],
            ],
            'status' => VideoScriptStatus::Draft,
        ]);

        $variant = ScriptVariant::create([
            'video_script_id' => $script->getKey(),
            'variant_key' => $source->windowKey.':hook:1',
            'variant_type' => 'hook',
            'hook_index' => 1,
            'hook_type' => $hooks[0]['hook_type'],
            'content' => $hooks[0]['content'],
            'retention_rationale' => $hooks[0]['retention_rationale'],
            'score' => $hooks[0]['score'],
            'selected' => true,
            'source_news_ids' => $hooks[0]['source_news_ids'],
        ]);

        $run = app(AutomationOrchestrator::class)->createRun(
            pipeline: 'script_agents.pipeline',
            subject: $cluster,
            dryRun: false,
            meta: ['video_script_id' => (int) $script->getKey()],
            activeWindow: $source->windowKey,
        );

        $result = app(ScriptPipelineService::class)->factCheckScript($source, $run);

        $this->assertTrue($result->blocked);
        $this->assertSame(VideoScriptStatus::Draft, $script->fresh()->status);
        $this->assertNotNull($result->gate);
        $this->assertSame(ApprovalGateType::ScriptFactCheck, $result->gate->gate_type);
        $this->assertSame(ApprovalGateStatus::Pending, $result->gate->status);
        $this->assertSame((int) $variant->getKey(), (int) $result->gate->gateable_id);
        $this->assertContains(
            'Quantum teleportation achieved on Mars in 2027.',
            $result->gate->meta['blocked_claims'],
        );
        $this->assertDatabaseHas('script_fact_checks', [
            'video_script_id' => $script->getKey(),
            'claim' => 'Quantum teleportation achieved on Mars in 2027.',
            'verdict' => ScriptFactCheckVerdict::Unsupported->value,
        ]);

        $unsupported = $script->factChecks()
            ->where('claim', 'Quantum teleportation achieved on Mars in 2027.')
            ->firstOrFail();
        $this->assertContains($primaryId, $unsupported->source_news_ids);
        $this->assertNotNull($unsupported->evidence);

        app(ScriptPipelineService::class)->factCheckScript($source, $run);
        $this->assertSame(1, ApprovalGate::count());
    }

    public function test_approved_scripts_are_never_overwritten_by_automation(): void
    {
        $cluster = $this->makeScriptCluster();
        $source = app(ScriptSourceFactory::class)->fromModel($cluster);
        $scenes = [[
            'order' => 1,
            'type' => 'intro',
            'duration' => 45,
            'headline' => 'Approved legacy scene',
            'description' => 'Approved legacy scene',
            'narration' => 'Approved narration that must remain untouched.',
            'visual_keywords' => ['approved'],
        ]];

        $script = VideoScript::create([
            'news_id' => $source->primaryNewsId(),
            'title' => 'Approved protected script',
            'language' => 'en',
            'target_duration' => 45,
            'estimated_duration' => 45,
            'narration' => 'Approved narration that must remain untouched.',
            'scenes' => $scenes,
            'status' => VideoScriptStatus::Approved,
            'approved_at' => now(),
        ]);

        ScriptVariant::create([
            'video_script_id' => $script->getKey(),
            'variant_key' => $source->windowKey.':hook:1',
            'variant_type' => 'hook',
            'hook_index' => 1,
            'hook_type' => 'curiosity',
            'content' => 'Existing approved hook',
            'score' => 90,
            'selected' => true,
            'source_news_ids' => $source->newsIds(),
        ]);

        $run = app(AutomationOrchestrator::class)->createRun(
            pipeline: 'script_agents.pipeline',
            subject: $cluster,
            dryRun: false,
            meta: ['video_script_id' => (int) $script->getKey()],
            activeWindow: $source->windowKey,
        );

        $service = app(ScriptPipelineService::class);

        foreach (['generateVariants', 'composeScript', 'factCheckScript'] as $method) {
            /** @var ScriptPipelineResult $result */
            $result = $service->{$method}($source, $run);
            $this->assertTrue($result->blocked, $method.' must refuse to mutate an approved script.');
        }

        $script->refresh();
        $this->assertSame(VideoScriptStatus::Approved, $script->status);
        $this->assertSame('Approved narration that must remain untouched.', $script->narration);
        $this->assertSame($scenes, $script->scenes);
        $this->assertSame(1, $script->variants()->count());
        $this->assertSame(0, $script->factChecks()->count());
        $this->assertTrue((bool) $run->fresh()->meta['script_protected']);
    }

    public function test_explicit_hook_index_selects_the_deterministic_variant(): void
    {
        $cluster = $this->makeScriptCluster();
        $source = app(ScriptSourceFactory::class)->fromModel($cluster);
        $run = app(AutomationOrchestrator::class)->createRun(
            pipeline: 'script_agents.pipeline',
            subject: $cluster,
            dryRun: false,
            activeWindow: $source->windowKey,
        );
        $service = app(ScriptPipelineService::class);

        $generated = $service->generateVariants($source, $run);
        $this->assertCount(3, $generated->variants);

        $composed = $service->composeScript($source, $run, 2);
        $this->assertFalse($composed->blocked, $composed->message);
        $this->assertNotNull($composed->script);

        $script = $composed->script->fresh();
        $selected = $script->variants()->where('selected', true)->firstOrFail();
        $this->assertSame(2, $selected->hook_index);
        $this->assertStringStartsWith((string) $selected->content, (string) $script->scenes[0]['narration']);
    }

    public function test_optional_external_ai_calls_are_budgeted_and_ledgered(): void
    {
        $cluster = $this->makeScriptCluster();
        $source = app(ScriptSourceFactory::class)->fromModel($cluster);
        $claims = array_map(fn (array $claim): string => $claim['claim'], $source->claims);

        config([
            'automation.dry_run' => false,
            'automation.script_agents.use_ai' => true,
            'automation.providers.default' => 'ollama',
        ]);

        Http::fake([
            '*' => Http::response([
                'response' => (string) json_encode([
                    'hooks' => [
                        ['content' => $claims[0], 'hook_type' => 'curiosity', 'retention_rationale' => 'Provider hook one.'],
                        ['content' => $claims[1], 'hook_type' => 'stat', 'retention_rationale' => 'Provider hook two.'],
                        ['content' => $claims[2], 'hook_type' => 'question', 'retention_rationale' => 'Provider hook three.'],
                    ],
                ]),
            ], 200),
        ]);

        $result = app(ScriptPipelineService::class)->runPipeline($cluster);

        $this->assertFalse($result->blocked, $result->message);
        $this->assertSame(1, AiUsageLedgerEntry::count());

        $ledger = AiUsageLedgerEntry::query()->firstOrFail();
        $this->assertSame('script', $ledger->purpose);
        $this->assertSame('ollama', $ledger->provider);
        $this->assertGreaterThan(0, $ledger->cost_micros);
        $this->assertSame((int) $result->run->getKey(), (int) $ledger->automation_run_id);
        $this->assertSame([$claims[0], $claims[1], $claims[2]], $result->script->variants()
            ->orderBy('hook_index')
            ->pluck('content')
            ->all());
        Http::assertSentCount(1);
    }

    public function test_budget_denial_prevents_external_calls_and_blocks_the_run(): void
    {
        $cluster = $this->makeScriptCluster();

        config([
            'automation.dry_run' => false,
            'automation.script_agents.use_ai' => true,
            'automation.providers.default' => 'ollama',
            'automation.budget.per_call_limit_micros' => 1,
        ]);

        $result = app(ScriptPipelineService::class)->runPipeline($cluster);

        $this->assertTrue($result->blocked);
        $this->assertSame(AutomationRunStatus::Failed, $result->run->status);
        $this->assertSame(0, AiUsageLedgerEntry::count());
        $this->assertSame(0, VideoScript::count());
        Http::assertNothingSent();
    }
}
