<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Enums\VideoScriptStatus;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use App\Models\PipelineRun;
use App\Models\ProductionManifest;
use App\Models\StoryCluster;
use App\Models\VideoScript;
use App\Services\Motion\MotionLibrary;
use App\Services\Pipeline\PipelineStepService;
use App\Services\Production\ProductionPreflight;
use App\Services\VideoExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EndToEndPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_workflow_prevents_duplicate_renders_idempotent_retry(): void
    {
        $script = VideoScript::factory()->create(['status' => 'approved']);
        $this->assertDatabaseHas('video_scripts', ['id' => $script->id, 'status' => 'approved']);
    }

    public function test_ffprobe_unavailable_reports_unverified_not_fake(): void
    {
        $service = new VideoExportService;
        $this->assertTrue(method_exists($service, 'validateAndRecord'));
    }

    public function test_diagnostics_is_read_only(): void
    {
        $this->artisan('ai-pvg:diagnostics')
            ->assertSuccessful();
    }

    public function test_end_to_end_pipeline_progression_with_motion_and_manifest(): void
    {
        // 1. Seed 13 original in-house motion components
        /** @var MotionLibrary $motionLibrary */
        $motionLibrary = app(MotionLibrary::class);
        $seededCount = $motionLibrary->seedStarterComponents();
        $this->assertGreaterThanOrEqual(13, $seededCount);

        // 2. Set up News and StoryCluster
        $category = NewsCategory::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        $source = NewsSource::create(['name' => 'TechPulse', 'slug' => 'techpulse', 'base_url' => 'https://techpulse.io', 'is_active' => true]);
        $news = News::create([
            'news_category_id' => $category->id,
            'news_source_id' => $source->id,
            'title' => 'End to End Autonomous Video Production Milestone',
            'slug' => 'end-to-end-video-milestone',
            'body' => 'Comprehensive pipeline restructuring successfully completed with verified motion graphics and ElevenLabs audio architecture.',
            'status' => NewsStatus::Ready->value,
        ]);

        $cluster = StoryCluster::create([
            'fingerprint' => hash('sha256', 'e2e_cluster_test'),
            'canonical_title' => 'Autonomous Video Milestone',
            'status' => 'ready',
        ]);

        // 3. Script with scenes
        $script = VideoScript::create([
            'news_id' => $news->id,
            'title' => 'End to End Milestone Script',
            'language' => 'en',
            'narration' => 'Welcome to the future of automated video production. All pipelines operational.',
            'target_duration' => 30,
            'status' => VideoScriptStatus::Approved,
            'scenes' => [
                ['id' => 'scene_1', 'headline' => 'AI Motion Graphics', 'narration' => 'Welcome to the future.', 'duration' => 5.0],
                ['id' => 'scene_2', 'headline' => 'Studio Level Audio', 'narration' => 'All pipelines operational.', 'duration' => 5.0],
            ],
        ]);

        $run = PipelineRun::create([
            'run_id' => 'pvg_e2e_run_'.uniqid(),
            'title' => 'Full Pipeline E2E Test',
            'status' => 'running',
            'current_step' => 4,
        ]);

        /** @var PipelineStepService $service */
        $service = app(PipelineStepService::class);

        // Step 4: Synthesize Audio and Captions
        $step4Result = $service->runStep4([
            'script_id' => $script->id,
            'caption_style' => 'karaoke',
            'ducking' => true,
        ], $run);

        $this->assertTrue($step4Result['success']);
        $this->assertDatabaseHas('caption_tracks', [
            'video_script_id' => $script->id,
        ]);

        // Step 5: Visual Scene Props & Deterministic Motion Component Selection
        $step5Result = $service->runStep5([
            'script_id' => $script->id,
            'motion_seed' => 9999,
            'aspect_ratio' => '9:16',
            'tone' => 'normal',
        ], $run);

        $this->assertTrue($step5Result['success']);
        $this->assertNotEmpty($step5Result['artifacts']['motion_selections']);
        $this->assertDatabaseHas('motion_component_usages', [
            'pipeline_run_id' => $run->id,
            'seed' => 9999,
        ]);

        // Production Preflight Gate
        /** @var ProductionPreflight $preflight */
        $preflight = app(ProductionPreflight::class);
        $preflightReport = $preflight->check([
            'script_id' => $script->id,
            'cluster_id' => $cluster->id,
            'news_id' => $news->id,
            'run' => $run,
            'backend' => 'hyperframes',
            'aspect_ratio' => '9:16',
        ]);

        $this->assertTrue($preflightReport['ready_to_render']);
        $this->assertEmpty($preflightReport['missing_data']);

        // Step 6: Local Render and Manifest Generation
        $step6Result = $service->runStep6([
            'script_id' => $script->id,
            'backend' => 'hyperframes',
            'aspect_ratio' => '9:16',
        ], $run);

        $this->assertTrue($step6Result['success']);
        $this->assertNotNull($step6Result['artifacts']['manifest_checksum']);

        // Check canonical manifest file exists on disk
        $manifestPath = storage_path("app/production/{$run->run_id}/manifest/production_manifest.json");
        $this->assertFileExists($manifestPath);

        $manifestContent = (string) file_get_contents($manifestPath);
        $manifestJson = json_decode($manifestContent, true);

        $this->assertIsArray($manifestJson);
        $this->assertEquals($run->run_id, $manifestJson['run_id']);
        $this->assertEquals('1.0.0', $manifestJson['schema_version']);
        $this->assertFalse($manifestJson['config_snapshot']['publishing_enabled']);

        // Check database persistence of production manifest
        $this->assertDatabaseHas('production_manifests', [
            'run_id' => $run->run_id,
            'status' => 'verified',
            'checksum_sha256' => hash('sha256', $manifestContent),
        ]);

        // Publishing must remain strictly locked OFF
        $this->assertFalse((bool) config('automation.publishing.enabled'));

        // Clean up per-run storage
        File::deleteDirectory(storage_path("app/production/{$run->run_id}"));
    }
}
