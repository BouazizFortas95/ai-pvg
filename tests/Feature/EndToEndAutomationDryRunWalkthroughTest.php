<?php

namespace Tests\Feature;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStageStatus;
use App\Enums\AutomationTrigger;
use App\Enums\CircuitState;
use App\Enums\PresetFormat;
use App\Enums\ScriptFactCheckVerdict;
use App\Enums\TriageBand;
use App\Enums\VideoRenderStatus;
use App\Exceptions\Automation\BudgetExceededException;
use App\Models\AiUsageLedgerEntry;
use App\Models\AudioAsset;
use App\Models\AutomationRun;
use App\Models\BudgetPeriod;
use App\Models\CaptionTrack;
use App\Models\CoPilotReviewSession;
use App\Models\FeatureFlag;
use App\Models\MetadataVariant;
use App\Models\News;
use App\Models\NewsScore;
use App\Models\NewsSource;
use App\Models\PublishingApprovalSession;
use App\Models\RenderVariant;
use App\Models\SceneAsset;
use App\Models\StoryCluster;
use App\Models\StoryClusterNews;
use App\Models\ThumbnailVariant;
use App\Models\User;
use App\Models\VideoRender;
use App\Models\VideoScript;
use App\Models\VisualAsset;
use App\Models\YouTubePublishingState;
use App\Services\AI\Captions\AudioMixService;
use App\Services\AI\Captions\CaptionTrackBuilder;
use App\Services\AI\Captions\WordTimingService;
use App\Services\AI\ScriptPipeline\DirectiveValidator;
use App\Services\AI\ScriptPipeline\FactCheckerAgent;
use App\Services\AI\ScriptPipeline\HookSpecialistAgent;
use App\Services\AI\ScriptPipeline\NarrativeArchitectAgent;
use App\Services\AI\ScriptPipeline\ScriptSourceFactory;
use App\Services\Automation\AutomationOrchestrator;
use App\Services\Automation\AutopilotGateService;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\CircuitBreaker;
use App\Services\Automation\DryRunContext;
use App\Services\Automation\FanOutBatchService;
use App\Services\Automation\PresetValidator;
use App\Services\Automation\RemotionPropInjector;
use App\Services\Automation\ScenePropBuilder;
use App\Services\Automation\Scoring\NewsScoringService;
use App\Services\Automation\Stages\AutoGreenlightStage;
use App\Services\Automation\Stages\ClusterStoriesStage;
use App\Services\Automation\Stages\ComposeScriptStage;
use App\Services\Automation\Stages\FactCheckScriptStage;
use App\Services\Automation\Stages\GenerateScriptVariantsStage;
use App\Services\Automation\Stages\ScoreNewsStage;
use App\Services\Automation\ThumbnailBuilder;
use App\Services\Publishing\CoPilotReviewService;
use App\Services\Publishing\YouTubePublishService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\BuildsTriageFixtures;
use Tests\TestCase;

class EndToEndAutomationDryRunWalkthroughTest extends TestCase
{
    use BuildsTriageFixtures;
    use RefreshDatabase;

    private CarbonImmutable $asOf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asOf = CarbonImmutable::parse('2026-10-25 12:00:00');

        Carbon::setTestNow($this->asOf);
        Http::preventStrayRequests();

        config([
            'automation.enabled' => true,
            'automation.dry_run' => true,
            'automation.triage.enabled' => true,
            'automation.triage.language' => 'en',
            'automation.triage.target_duration' => 45,
            'automation.news_scoring.auto_greenlight_min' => 85,
            'automation.news_scoring.review_band_min' => 60,
            'automation.news_scoring.velocity_window_hours' => 12,
            'automation.news_scoring.velocity_target_sources' => 5,
            'automation.news_scoring.coverage_similarity_threshold' => 0.35,
            'automation.news_scoring.novelty_window_days' => 7,
            'automation.news_scoring.recency_half_life_hours' => 24,
            'automation.story_clustering.window_hours' => 48,
            'automation.story_clustering.similarity_threshold' => 0.5,
            'automation.story_clustering.min_sources' => 3,
            'automation.story_clustering.max_sources' => 5,
            'automation.script_agents.enabled' => true,
            'automation.script_agents.use_ai' => false,
            'automation.script_agents.language' => 'en',
            'automation.script_agents.target_duration' => 45,
            'automation.script_agents.duration_tolerance' => 5,
            'automation.script_agents.hook_word_limit' => 12,
            'automation.script_agents.max_scene_seconds' => 12,
            'automation.script_agents.claim_coverage_threshold' => 0.30,
            'automation.captions.enabled' => true,
            'automation.captions.language_default' => 'en',
            'automation.captions.target_duration' => 45,
            'automation.assets.enabled' => true,
            'automation.formats.enabled' => true,
            'automation.publishing.enabled' => false,
            'automation.budget.monthly_limit_micros' => 5_000_000,
            'automation.budget.per_run_limit_micros' => 250_000,
            'automation.budget.per_call_limit_micros' => 50_000,
            'automation.approval.require_dual_public_publish' => true,
        ]);

        foreach ([
            'triage.news_scoring_enabled',
            'script_agents.enabled',
            'captions.enabled',
            'assets.enabled',
            'formats.enabled',
        ] as $flag) {
            FeatureFlag::setFlag($flag, true);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_full_dry_run_walkthrough_covers_g01_to_g06_and_phase_h_without_side_effects(): void
    {
        $dryRun = app(DryRunContext::class);
        $orchestrator = app(AutomationOrchestrator::class);

        /*
         * G-01 — Control plane: the run starts pending, dry-run blocks paid
         * calls, the breaker fails closed, and a reservation/release leaves
         * both the period and the run at zero spend.
         */
        $run = $orchestrator->createRun(
            pipeline: 'walkthrough.e2e',
            trigger: AutomationTrigger::Manual,
            dryRun: true,
            meta: ['walkthrough' => 'g01-g06-phase-h'],
        );

        $this->assertSame(AutomationRunStatus::Pending, $run->status);
        $this->assertTrue($run->dry_run);
        $this->assertTrue($dryRun->isDryRun($run));
        $this->assertFalse($dryRun->allowsPaidCalls($run));
        $this->assertFalse($dryRun->canPerformExternalAction($run));
        $this->assertSame('ollama', $dryRun->providerFor('ai', $run));
        $this->assertSame('mock', $dryRun->providerFor('tts', $run));

        $budget = app(BudgetGuard::class);
        $breaker = app(CircuitBreaker::class);
        $period = $budget->currentPeriod();

        $this->assertNotNull($period);
        $this->assertSame(0, $period->spent_micros);
        $this->assertSame(0, $period->reserved_micros);

        $breaker->open(CircuitBreaker::BUDGET_PROVIDER, 'Walkthrough breaker probe');

        try {
            $budget->authorize(1_000, $run);
            $this->fail('A budget authorization must fail while the breaker is open.');
        } catch (BudgetExceededException $exception) {
            $this->assertStringContainsString('circuit breaker is open', $exception->getMessage());
        }

        $breaker->close(CircuitBreaker::BUDGET_PROVIDER);
        $this->assertSame(CircuitState::Closed, $breaker->state());
        $this->assertTrue($breaker->canAttempt());

        $reservation = $budget->authorize(10_000, $run);
        $this->assertSame(10_000, $reservation->amountMicros);
        $this->assertSame((int) $run->getKey(), $reservation->runId);

        $budget->release($reservation);

        $this->assertSame(0, $budget->currentPeriod(false)?->spent_micros);
        $this->assertSame(0, $budget->currentPeriod(false)?->reserved_micros);

        /*
         * G-02 — Triage: score five distinct-source tech articles, cluster
         * them, and evaluate auto-greenlight in dry-run mode.
         */
        $news = $this->makeTechNewsCluster();
        $scores = app(NewsScoringService::class)->scoreMany($news, $this->asOf);

        $this->assertCount(5, $scores);

        foreach ($news as $article) {
            $score = $scores[(int) $article->getKey()];

            $this->assertSame(TriageBand::AutoGreenlight, $score->band);
            $this->assertGreaterThanOrEqual(85.0, (float) $score->composite_score);
            $this->assertSame(5, $score->breakdown['components']['velocity']['evidence']['distinct_sources']);
            $this->assertSame('g02-news-score-v1', $score->breakdown['version']);
        }

        $scoreStage = $orchestrator->executeStage(
            $run,
            'score_news',
            app(ScoreNewsStage::class),
            ['news_id' => (int) $news[0]->getKey(), 'as_of' => $this->asOf->toIso8601String()],
        );

        $this->assertTrue($scoreStage->successful(), (string) $scoreStage->errorMessage);
        $this->assertSame((int) $news[0]->getKey(), $scoreStage->output['news_id']);
        $this->assertSame($scores[(int) $news[0]->getKey()]->breakdown, $scoreStage->output['breakdown']);
        $this->assertSame($scores[(int) $news[0]->getKey()]->band?->value, $scoreStage->output['band']);

        $clusterStage = $orchestrator->executeStage(
            $run,
            'cluster_stories',
            app(ClusterStoriesStage::class),
            ['as_of' => $this->asOf->toIso8601String()],
        );

        $this->assertTrue($clusterStage->successful(), (string) $clusterStage->errorMessage);
        $this->assertSame(1, $clusterStage->output['cluster_count']);
        $this->assertSame(5, $clusterStage->output['clusters'][0]['source_count']);

        $cluster = StoryCluster::query()
            ->with(['news.newsScore'])
            ->findOrFail((int) $clusterStage->output['clusters'][0]['story_cluster_id']);

        $this->assertSame(5, $cluster->source_count);
        $this->assertCount(5, $cluster->news);
        $newsIds = array_map(fn (News $article): int => (int) $article->getKey(), $news);
        $this->assertSame([], array_diff($cluster->newsIds(), $newsIds));

        $greenlightStage = $orchestrator->executeStage(
            $run,
            'auto_greenlight',
            app(AutoGreenlightStage::class),
            ['story_cluster_id' => (int) $cluster->getKey(), 'as_of' => $this->asOf->toIso8601String()],
        );

        $this->assertTrue($greenlightStage->successful(), (string) $greenlightStage->errorMessage);
        $this->assertSame('auto_greenlight', $greenlightStage->output['action']);
        $this->assertSame('auto_greenlight', $greenlightStage->output['band']);
        $this->assertTrue($greenlightStage->output['dry_run']);
        $this->assertNull($greenlightStage->output['video_script_id']);

        $plannedScript = $greenlightStage->output['planned_script'];

        $this->assertIsArray($plannedScript);
        $this->assertSame(45, $plannedScript['estimated_duration']);
        $this->assertSame(5, $plannedScript['source_count']);
        $this->assertSame($cluster->newsIds(), $plannedScript['source_news_ids']);
        $this->assertCount(5, $plannedScript['scenes']);
        $this->assertSame('intro', $plannedScript['scenes'][0]['type']);
        $this->assertSame('conclusion', $plannedScript['scenes'][4]['type']);
        $this->assertSame(45, array_sum(array_column($plannedScript['scenes'], 'duration')));
        $this->assertSame(0, VideoScript::query()->count());

        /*
         * G-03 — Script agents: run the three real orchestrated stages. Each
         * stage produces DryRunContext-labeled output and must not create a
         * production VideoScript, variants, or fact-check rows.
         */
        $variantsStage = $orchestrator->executeStage(
            $run,
            'generate_variants',
            app(GenerateScriptVariantsStage::class),
            ['story_cluster_id' => (int) $cluster->getKey()],
        );

        $this->assertTrue($variantsStage->successful(), (string) $variantsStage->errorMessage);
        $plannedVariants = $variantsStage->output['planned_variants'];
        $this->assertTrue($plannedVariants['_automation']['dry_run']);
        $this->assertTrue($plannedVariants['_automation']['labeled']);
        $this->assertSame('g03_generate_variants', $plannedVariants['_automation']['stage']);
        $this->assertCount(3, $plannedVariants['variants']);

        $composeStage = $orchestrator->executeStage(
            $run,
            'compose_script',
            app(ComposeScriptStage::class),
            ['story_cluster_id' => (int) $cluster->getKey(), 'hook_index' => 1],
        );

        $this->assertTrue($composeStage->successful(), (string) $composeStage->errorMessage);
        $plannedScenes = $composeStage->output['planned_scenes'];
        $this->assertTrue($plannedScenes['_automation']['dry_run']);
        $this->assertSame('g03_compose_script', $plannedScenes['_automation']['stage']);
        $this->assertSame(45, $plannedScenes['estimated_duration']);

        $factCheckStage = $orchestrator->executeStage(
            $run,
            'fact_check_script',
            app(FactCheckScriptStage::class),
            ['story_cluster_id' => (int) $cluster->getKey()],
        );

        $this->assertTrue($factCheckStage->successful(), (string) $factCheckStage->errorMessage);
        $plannedChecks = $factCheckStage->output['planned_fact_checks'];
        $this->assertTrue($plannedChecks['_automation']['dry_run']);
        $this->assertSame('g03_fact_check', $plannedChecks['_automation']['stage']);
        $this->assertFalse($plannedChecks['blocked']);

        $run = $orchestrator->completeRun($run);
        $this->assertSame(AutomationRunStatus::Completed, $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(6, $run->stageRuns()->count());

        foreach ($run->stageRuns()->get() as $stageRun) {
            $this->assertSame(AutomationStageStatus::Completed, $stageRun->status);
            $this->assertSame(0, (int) $stageRun->cost_micros);
        }

        $source = app(ScriptSourceFactory::class)->fromModel($cluster->fresh(['news.newsScore']));
        $hooks = app(HookSpecialistAgent::class)->generate($source);

        $this->assertCount(3, $hooks);
        $this->assertSame(['curiosity', 'stat', 'question'], array_column($hooks, 'hook_type'));
        $this->assertCount(3, array_unique(array_column($hooks, 'content')));

        foreach ($hooks as $hook) {
            $this->assertNotSame('', trim($hook['content']));
            $this->assertLessThanOrEqual(12, count(preg_split('/\s+/', trim($hook['content'])) ?: []));
            $this->assertNotSame([], $hook['source_news_ids']);
            $this->assertSame([], array_diff($hook['source_news_ids'], $source->newsIds()));
            $this->assertSame('deterministic-local', $hook['provider']);
            $this->assertSame('g03-hooks-v1', $hook['model']);
        }

        $this->assertSame(array_column($hooks, 'content'), array_column($plannedVariants['variants'], 'content'));

        $scenes = app(NarrativeArchitectAgent::class)->compose($source, (string) $hooks[0]['content']);
        $validatedScenes = app(DirectiveValidator::class)->validate($scenes, $source->targetDuration, $source->newsIds());

        $this->assertSame($scenes, $validatedScenes);
        $this->assertCount(7, $scenes);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_column($scenes, 'order'));
        $this->assertSame('intro', $scenes[0]['type']);
        $this->assertSame('conclusion', $scenes[6]['type']);
        $this->assertSame(45, array_sum(array_column($scenes, 'duration')));
        $this->assertSame(array_column($scenes, 'narration'), array_column($plannedScenes['scenes'], 'narration'));

        foreach ($scenes as $scene) {
            $this->assertSame(
                ['camera_motion', 'b_roll_query', 'highlight_text', 'sfx_cue', 'emotion_tone', 'duration_seconds'],
                array_keys($scene['directives']),
            );
            $this->assertSame($scene['duration'], $scene['directives']['duration_seconds']);
            $this->assertNotSame([], $scene['source_news_ids']);
            $this->assertSame([], array_diff($scene['source_news_ids'], $source->newsIds()));
        }

        $checks = app(FactCheckerAgent::class)->check(
            $scenes,
            $source->news,
            $source->factSheet,
            [['claim' => (string) $hooks[0]['content'], 'source_news_ids' => $hooks[0]['source_news_ids']]],
        );

        $this->assertCount(3, $checks);
        $this->assertSame(
            array_column($checks, 'claim_hash'),
            array_column($plannedChecks['checks'], 'claim_hash'),
        );

        foreach ($checks as $check) {
            $this->assertSame(ScriptFactCheckVerdict::Supported, $check['verdict']);
            $this->assertNotSame([], $check['source_news_ids']);
            $this->assertNotSame('', trim($check['evidence']));
            $this->assertGreaterThan(0.0, (float) $check['coverage']);
            $this->assertGreaterThanOrEqual(80.0, (float) $check['critic_score']);
        }

        /*
         * G-04 — Captions and audio: estimated timings are monotonic, caption
         * chunks preserve the narration, and the FFmpeg plan contains music
         * ducking plus scene-directed SFX cues. Nothing is executed.
         */
        $narration = implode(' ', array_column($scenes, 'narration'));
        $durationMs = (float) array_sum(array_column($scenes, 'duration')) * 1000;
        $timings = app(WordTimingService::class)->estimateFromText($narration, $durationMs);

        $this->assertNotSame([], $timings);
        $previousEnd = 0;

        foreach ($timings as $timing) {
            $this->assertSame('estimated', $timing['source']);
            $this->assertGreaterThanOrEqual($previousEnd, $timing['start_ms']);
            $this->assertGreaterThan($timing['start_ms'], $timing['end_ms']);
            $previousEnd = $timing['end_ms'];
        }

        $this->assertLessThanOrEqual((int) $durationMs, $previousEnd);

        $chunks = app(CaptionTrackBuilder::class)->buildChunks($narration, 'en');

        $this->assertNotSame([], $chunks);
        $this->assertSame($narration, implode(' ', array_column($chunks, 'text')));

        foreach ($chunks as $chunk) {
            $this->assertSame('en', $chunk['language']);
            $this->assertFalse($chunk['rtl']);
            $this->assertNotSame('', trim($chunk['text']));
            $this->assertLessThanOrEqual(10, count(preg_split('/\s+/', trim($chunk['text'])) ?: []));
        }

        $filterGraph = app(AudioMixService::class)->buildFilterGraph([
            ['kind' => 'narration', 'storage_path' => 'storage/app/private/walkthrough/narration.mp3'],
            ['kind' => 'music', 'storage_path' => 'storage/app/private/walkthrough/music.mp3'],
        ], $scenes);

        $this->assertStringContainsString('sidechaincompress', $filterGraph);
        $this->assertStringContainsString('a_ducked', $filterGraph);
        $this->assertStringContainsString('-map [outmix]', $filterGraph);
        $this->assertStringContainsString('narration.mp3', $filterGraph);
        $this->assertStringContainsString('music.mp3', $filterGraph);

        $sfxPlan = $this->sfxPlan($scenes);

        $this->assertCount(5, $sfxPlan);
        $this->assertSame('whoosh', $sfxPlan[0]['cue']);
        $this->assertSame('riser', $sfxPlan[array_key_last($sfxPlan)]['cue']);

        foreach ($sfxPlan as $cue) {
            $this->assertContains($cue['cue'], ['whoosh', 'impact', 'riser']);
            $this->assertGreaterThanOrEqual(0, $cue['at_seconds']);
        }

        /*
         * G-05 — Visual assets: missing verified assets produce a truthful
         * fallback, while a verified SceneAsset reaches the Remotion payload
         * with its real storage path and attribution.
         */
        $script = VideoScript::factory()->create([
            'news_id' => (int) $news[0]->getKey(),
            'title' => $source->title,
            'language' => $source->language,
            'target_duration' => $source->targetDuration,
            'estimated_duration' => 45,
            'narration' => $narration,
            'scenes' => $scenes,
            'status' => 'draft',
            'ai_provider' => 'deterministic-local',
            'ai_model' => 'walkthrough',
        ]);

        $directive = $scenes[0]['directives'];
        $propBuilder = app(ScenePropBuilder::class);
        $injector = app(RemotionPropInjector::class);

        $fallbackProps = $propBuilder->buildProps((int) $script->getKey(), 1, $directive);

        $this->assertTrue($fallbackProps['truthful_fallback']);
        $this->assertSame([], $fallbackProps['assets']);

        $fallbackPayload = $injector->inject([
            'title' => $script->title,
            'scenes' => $script->scenes,
        ], $fallbackProps);

        $this->assertNull($fallbackPayload['visualAssets']);

        $visualAsset = VisualAsset::query()->create([
            'type' => 'image',
            'storage_path' => 'visuals/walkthrough-helios-chip.png',
            'source_url' => 'https://example.test/walkthrough-helios-chip.png',
            'domain' => 'example.test',
            'license_type' => 'cc_by',
            'attribution_text' => 'Example Source, CC BY 4.0',
            'status' => 'verified',
            'mime_type' => 'image/png',
            'byte_size' => 12_345,
            'width' => 1920,
            'height' => 1080,
            'checksum_sha256' => hash('sha256', 'walkthrough-helios-chip'),
            'metadata' => ['walkthrough' => true],
        ]);

        SceneAsset::query()->create([
            'video_script_id' => (int) $script->getKey(),
            'scene_index' => 1,
            'visual_asset_id' => (int) $visualAsset->getKey(),
            'role' => 'b_roll',
            'directive_query' => $directive['b_roll_query'],
            'attribution_text' => 'Example Source, CC BY 4.0',
            'status' => 'verified',
        ]);

        $props = $propBuilder->buildProps((int) $script->getKey(), 1, $directive);

        $this->assertFalse($props['truthful_fallback']);
        $this->assertCount(1, $props['assets']);
        $this->assertSame('visuals/walkthrough-helios-chip.png', $props['assets'][0]['path']);
        $this->assertSame('Example Source, CC BY 4.0', $props['assets'][0]['attribution']);
        $this->assertSame('verified', $props['assets'][0]['status']);

        $injected = $injector->inject([
            'title' => $script->title,
            'scenes' => $script->scenes,
        ], $props);

        $this->assertSame($props['assets'], $injected['visualAssets']);
        $this->assertSame($props, $injected['sceneProps']);

        /*
         * G-06 — Multi-format fan-out: all three presets, a midpoint
         * thumbnail plan, and two dry-run A/B metadata variants. The
         * planning services never persist render, thumbnail, or metadata
         * rows.
         */
        $render = VideoRender::factory()->create([
            'video_script_id' => (int) $script->getKey(),
            'template' => 'cyber',
            'status' => VideoRenderStatus::Completed,
            'output_path' => 'storage/app/private/walkthrough/final.mp4',
            'duration' => 45,
            'resolution' => '1080x1920',
            'fps' => 30,
            'started_at' => $this->asOf->subMinutes(2),
            'completed_at' => $this->asOf,
        ]);

        $fanOut = app(FanOutBatchService::class)->planFanOut((int) $render->getKey());

        $this->assertTrue($fanOut['dry_run']);
        $this->assertCount(3, $fanOut['variants']);
        $this->assertSame(
            [PresetFormat::Shorts916->value, PresetFormat::Long169->value, PresetFormat::Square11->value],
            array_column($fanOut['variants'], 'preset'),
        );

        $expectedResolutions = [
            PresetFormat::Shorts916->value => ['width' => 1080, 'height' => 1920],
            PresetFormat::Long169->value => ['width' => 1920, 'height' => 1080],
            PresetFormat::Square11->value => ['width' => 1080, 'height' => 1080],
        ];

        foreach ($fanOut['variants'] as $variant) {
            $preset = app(PresetValidator::class)->validatePreset($variant['preset']);
            $this->assertSame($expectedResolutions[$variant['preset']], $preset->resolution());
            $this->assertSame($preset->resolution()['width'], $variant['width']);
            $this->assertSame($preset->resolution()['height'], $variant['height']);
            $this->assertNull($variant['frame_offset_ms']);
        }

        $thumbnail = app(ThumbnailBuilder::class)->buildMidpointThumbnail(
            (string) $render->output_path,
            (float) $render->duration,
        );

        $this->assertTrue($thumbnail['dry_run']);
        $this->assertSame(22_500, $thumbnail['offset_ms']);
        $this->assertSame(1080, $thumbnail['width']);
        $this->assertSame(1080, $thumbnail['height']);

        $metadataVariants = $this->planAbMetadata($script->title, $scenes);

        $this->assertCount(2, $metadataVariants);
        $this->assertSame(['a', 'b'], array_column($metadataVariants, 'variant_key'));
        $this->assertCount(2, array_unique(array_column($metadataVariants, 'title_text')));
        $this->assertNotSame($metadataVariants[0]['tags_json'], $metadataVariants[1]['tags_json']);

        foreach ($metadataVariants as $variant) {
            $this->assertTrue($variant['dry_run']);
            $this->assertSame('planned', $variant['status']);
            $this->assertNotSame('', trim($variant['title_text']));
            $this->assertNotSame('', trim($variant['description_text']));
        }

        $this->assertSame(0, RenderVariant::query()->count());
        $this->assertSame(0, ThumbnailVariant::query()->count());
        $this->assertSame(0, MetadataVariant::query()->count());

        /*
         * Phase H — Autopilot preflight, Co-Pilot audit, and the dual-admin
         * publishing guard. The publishing service authorizes only; it never
         * uploads or changes YouTube state.
         */
        $autopilot = app(AutopilotGateService::class);
        $snapshot = $autopilot->snapshot();

        $this->assertTrue($snapshot['allowed'], implode(', ', $snapshot['blocked']));
        $this->assertSame([], $snapshot['blocked']);
        $this->assertSame('closed', $snapshot['breaker_state']);
        $this->assertGreaterThan(0, $snapshot['budget_remaining_micros']);
        $autopilot->assertAllowed();

        FeatureFlag::setFlag('formats.enabled', false);
        $blockedSnapshot = $autopilot->snapshot();
        $this->assertFalse($blockedSnapshot['allowed']);
        $this->assertContains('g06_formats', $blockedSnapshot['blocked']);

        FeatureFlag::setFlag('formats.enabled', true);
        $this->assertTrue($autopilot->snapshot()['allowed']);

        $admin = User::factory()->admin()->create();
        $regular = User::factory()->create();
        $reviewService = app(CoPilotReviewService::class);

        $this->assertTrue($reviewService->review(
            (int) $admin->getKey(),
            (int) $script->getKey(),
            CoPilotReviewSession::ACTION_APPROVE,
            'Walkthrough dry-run review',
        ));

        $this->assertDatabaseHas('co_pilot_review_sessions', [
            'user_id' => (int) $admin->getKey(),
            'target_type' => CoPilotReviewSession::TARGET_SCRIPT,
            'target_id' => (int) $script->getKey(),
            'action' => CoPilotReviewSession::ACTION_APPROVE,
            'status' => 'completed',
        ]);

        try {
            $reviewService->review(
                (int) $regular->getKey(),
                (int) $script->getKey(),
                CoPilotReviewSession::ACTION_APPROVE,
            );
            $this->fail('A non-admin must not be able to review Co-Pilot items.');
        } catch (AuthorizationException) {
            // Expected: Co-Pilot review is admin-only.
        }

        config(['automation.publishing.enabled' => true]);
        FeatureFlag::setFlag('publishing.enabled', true);

        $secondAdmin = User::factory()->admin()->create();

        PublishingApprovalSession::query()->create([
            'video_script_id' => (int) $script->getKey(),
            'status' => PublishingApprovalSession::STATUS_APPROVED,
            'first_approver_id' => (int) $admin->getKey(),
            'first_approved_at' => $this->asOf,
            'second_approver_id' => (int) $secondAdmin->getKey(),
            'second_approved_at' => $this->asOf,
        ]);

        $publishService = app(YouTubePublishService::class);

        $this->assertTrue($publishService->publishVideoScript((int) $script->getKey()));
        $this->assertSame(0, YouTubePublishingState::query()->count());

        $blockedScript = VideoScript::factory()->create([
            'news_id' => (int) $news[1]->getKey(),
            'title' => 'Blocked publishing walkthrough',
            'language' => 'en',
            'target_duration' => 45,
            'status' => 'draft',
            'scenes' => $scenes,
        ]);

        PublishingApprovalSession::query()->create([
            'video_script_id' => (int) $blockedScript->getKey(),
            'status' => PublishingApprovalSession::STATUS_APPROVED,
            'first_approver_id' => (int) $admin->getKey(),
            'first_approved_at' => $this->asOf,
            'second_approver_id' => (int) $admin->getKey(),
            'second_approved_at' => $this->asOf,
        ]);

        try {
            $publishService->assertPublicPublishAuthorized((int) $blockedScript->getKey());
            $this->fail('Publishing must reject the same administrator approving twice.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('two distinct administrators', $exception->getMessage());
        }

        /*
         * Zero-side-effect attestation: no HTTP calls, no paid spend, no
         * production media or publishing rows.
         */
        Http::assertNothingSent();

        $this->assertSame(0, AiUsageLedgerEntry::query()->count());
        $this->assertSame(0, (int) AutomationRun::query()->sum('budget_spent_micros'));
        $this->assertSame(0, (int) AutomationRun::query()->sum('budget_reserved_micros'));
        $this->assertSame(0, BudgetPeriod::query()->sum('spent_micros'));
        $this->assertSame(0, BudgetPeriod::query()->sum('reserved_micros'));
        $this->assertSame(0, AutomationRun::query()->where('dry_run', false)->count());
        $this->assertSame(2, AutomationRun::query()->count());
        $this->assertSame(5, NewsScore::query()->count());
        $this->assertSame(1, StoryCluster::query()->count());
        $this->assertSame(5, StoryClusterNews::query()->count());
        $this->assertSame(2, VideoScript::query()->count());
        $this->assertSame(1, VisualAsset::query()->count());
        $this->assertSame(1, SceneAsset::query()->count());
        $this->assertSame(0, CaptionTrack::query()->count());
        $this->assertSame(0, AudioAsset::query()->count());
        $this->assertSame(0, RenderVariant::query()->count());
        $this->assertSame(0, ThumbnailVariant::query()->count());
        $this->assertSame(0, MetadataVariant::query()->count());
        $this->assertSame(0, YouTubePublishingState::query()->count());
        $this->assertSame(1, CoPilotReviewSession::query()->count());
        $this->assertSame(2, PublishingApprovalSession::query()->count());
    }

    /**
     * @return array<int, News>
     */
    private function makeTechNewsCluster(): array
    {
        $sharedSummary = 'Breaking exclusive launch: OpenAI AI chip model security breach crisis response confirmed by independent testers.';
        $sharedContent = $sharedSummary.' The company said the AI chip model entered a security review after a breach report. '
            .'Regulators and industry analysts are tracking the crisis, and the launch is now a record for the sector. '
            .'Independent testers confirmed the model retained performance under sustained load while security teams reviewed '
            .'the breach response. OpenAI said the chip launch remains on schedule and that the security review is expected '
            .'to produce a public report. The story is developing across technology, business, and policy desks, with '
            .'multiple sources confirming the same sequence of events and the same set of disclosed facts. Analysts said '
            .'the record launch gives the company a lead in the AI chip market, but the security breach response will be '
            .'closely watched by regulators and customers.';

        $titles = [
            'Breaking: OpenAI launches exclusive AI chip model',
            'OpenAI exclusive AI chip model faces security review',
            'AI chip launch triggers OpenAI security review',
            'OpenAI security review follows chip model launch',
            'Exclusive AI chip model launch sets industry record',
        ];

        $news = [];

        foreach ($titles as $index => $title) {
            $news[] = $this->makeNewsWithSource(
                $title,
                NewsSource::factory()->create(),
                [
                    'summary' => $sharedSummary,
                    'content' => $sharedContent,
                    'published_at' => $this->asOf->subMinutes($index + 1),
                ],
            );
        }

        return $news;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array{at_seconds: int, cue: string}>
     */
    private function sfxPlan(array $scenes): array
    {
        $plan = [];
        $cursor = 0;

        foreach ($scenes as $scene) {
            $cue = (string) ($scene['directives']['sfx_cue'] ?? 'none');

            if ($cue !== 'none') {
                $plan[] = ['at_seconds' => $cursor, 'cue' => $cue];
            }

            $cursor += (int) $scene['duration'];
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    private function planAbMetadata(string $title, array $scenes): array
    {
        $keywords = [];

        foreach ($scenes as $scene) {
            foreach ((array) ($scene['visual_keywords'] ?? []) as $keyword) {
                $keywords[(string) $keyword] = true;
            }
        }

        $tags = array_slice(array_keys($keywords), 0, 5);

        return [
            [
                'variant_key' => 'a',
                'title_text' => $title.' — what the sources confirm',
                'description_text' => 'Source-grounded summary of the verified launch and security review.',
                'tags_json' => $tags,
                'status' => 'planned',
                'dry_run' => true,
            ],
            [
                'variant_key' => 'b',
                'title_text' => 'The AI chip security story in 45 seconds',
                'description_text' => 'Fast, curiosity-led framing of the same verified facts and sources.',
                'tags_json' => array_values(array_unique([...$tags, 'security', 'explainer'])),
                'status' => 'planned',
                'dry_run' => true,
            ],
        ];
    }
}
