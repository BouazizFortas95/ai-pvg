<?php

namespace Tests\Feature;

use App\Enums\MotionCategory;
use App\Enums\MotionComponentStatus;
use App\Enums\MotionLicenseStatus;
use App\Enums\VideoScriptStatus;
use App\Models\CaptionTrack;
use App\Models\MotionComponent;
use App\Models\MotionComponentUsage;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use App\Models\PipelineRun;
use App\Models\ProductionManifest;
use App\Models\StoryCluster;
use App\Models\VideoScript;
use App\Services\Motion\MotionLibrary;
use App\Services\Pipeline\PipelineStepService;
use App\Services\Production\ProductionManifestBuilder;
use App\Services\Production\ProductionPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductionPreflightAndManifestTest extends TestCase
{
    use RefreshDatabase;

    protected ProductionPreflight $preflight;

    protected ProductionManifestBuilder $manifestBuilder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preflight = new ProductionPreflight;
        $this->manifestBuilder = new ProductionManifestBuilder;

        config(['automation.publishing.enabled' => false]);
    }

    public function test_preflight_blocks_and_identifies_missing_data_per_step(): void
    {
        // Empty state: everything missing
        $report = $this->preflight->check();

        $this->assertFalse($report['ready_to_render']);
        $this->assertNotEmpty($report['missing_data']);

        $stepsWithMissing = array_unique(array_column($report['missing_data'], 'step'));
        $this->assertContains(1, $stepsWithMissing);
        $this->assertContains(2, $stepsWithMissing);
        $this->assertContains(3, $stepsWithMissing);
        $this->assertContains(4, $stepsWithMissing);

        // Verify owning step name is reported for clear diagnostics
        $step1Item = collect($report['missing_data'])->firstWhere('step', 1);
        $this->assertEquals('Prepare & Load News', $step1Item['step_name']);
    }

    public function test_preflight_blocks_unlicensed_reference_only_motion_components(): void
    {
        $run = PipelineRun::create([
            'run_id' => 'run_preflight_ref_test',
            'title' => 'Preflight Ref Test',
            'status' => 'running',
            'current_step' => 5,
        ]);

        $unlicensedComp = MotionComponent::create([
            'component_key' => 'ref_motion_badge_v1',
            'version' => 1,
            'category' => MotionCategory::HeadlineBar->value,
            'html_template' => '<div></div>',
            'gsap_script' => 'const tl = gsap.timeline();',
            'params_schema' => [],
            'supported_formats' => ['9:16'],
            'tone_compatibility' => ['normal'],
            'contrast_rating' => 6.0,
            'license_status' => MotionLicenseStatus::ReferenceOnly,
            'status' => MotionComponentStatus::Approved,
        ]);

        MotionComponentUsage::create([
            'pipeline_run_id' => $run->id,
            'motion_component_id' => $unlicensedComp->id,
            'scene_index' => 1,
            'applied_params' => [],
            'seed' => 42,
        ]);

        $report = $this->preflight->check(['run' => $run]);

        $motionMissing = collect($report['missing_data'])->firstWhere('key', 'motion_licensing');
        $this->assertNotNull($motionMissing);
        $this->assertEquals(5, $motionMissing['step']);
        $this->assertStringContainsString('reference_only', $motionMissing['details']);
    }

    public function test_preflight_passes_when_all_pipeline_requirements_met(): void
    {
        // 1. News source + ready news
        $cat = NewsCategory::create(['name' => 'Tech', 'slug' => 'tech', 'is_active' => true]);
        $src = NewsSource::create(['name' => 'Tech Wire', 'slug' => 'tech-wire', 'base_url' => 'https://example.com', 'is_active' => true]);
        $news = News::create([
            'news_category_id' => $cat->id,
            'news_source_id' => $src->id,
            'title' => 'Breakthrough AI Announcement',
            'slug' => 'breakthrough-ai',
            'body' => 'Article body text.',
            'status' => 'ready',
        ]);

        // 2. Story cluster
        $cluster = StoryCluster::create([
            'fingerprint' => hash('sha256', 'ai_cluster_test_'.uniqid()),
            'canonical_title' => 'AI Cluster',
            'status' => 'ready',
        ]);

        // 3. Script with scenes
        $script = VideoScript::create([
            'news_id' => $news->id,
            'title' => 'AI Script Title',
            'language' => 'en',
            'narration' => 'Spoken narration text for video.',
            'target_duration' => 30,
            'status' => VideoScriptStatus::Approved,
            'scenes' => [
                ['id' => 's1', 'headline' => 'Headline', 'narration' => 'Scene narration', 'duration' => 5.0],
            ],
        ]);

        // 4. Captions
        CaptionTrack::create([
            'video_script_id' => $script->id,
            'language' => 'en',
            'word_timings' => [['word' => 'Spoken', 'start' => 0.0, 'end' => 0.4]],
            'status' => 'verified',
        ]);

        // Run preflight with full context
        $report = $this->preflight->check([
            'script_id' => $script->id,
            'cluster_id' => $cluster->id,
            'news_id' => $news->id,
        ]);

        $this->assertTrue($report['ready_to_render']);
        $this->assertEmpty($report['missing_data']);
        $this->assertCount(8, $report['passed_checks']);
    }

    public function test_production_manifest_builder_persists_schema_and_checksum(): void
    {
        $runId = 'run_test_manifest_'.uniqid();

        $result = $this->manifestBuilder->build($runId, [
            'headline' => 'AI Revolution Milestone',
            'backend' => 'hyperframes',
            'aspect_ratio' => '9:16',
        ]);

        $this->assertArrayHasKey('manifest', $result);
        $this->assertArrayHasKey('checksum', $result);
        $this->assertFileExists($result['path']);

        $manifest = $result['manifest'];
        $this->assertEquals('1.0.0', $manifest['schema_version']);
        $this->assertEquals($runId, $manifest['run_id']);
        $this->assertFalse($manifest['config_snapshot']['publishing_enabled']);

        // Verify database persistence
        $this->assertDatabaseHas('production_manifests', [
            'run_id' => $runId,
            'status' => 'verified',
            'checksum_sha256' => $result['checksum'],
        ]);

        // Verify checksum integrity
        $fileContents = (string) file_get_contents($result['path']);
        $this->assertEquals($result['checksum'], hash('sha256', $fileContents));

        // Clean up
        File::deleteDirectory(storage_path("app/production/{$runId}"));
    }

    public function test_pipeline_step_5_and_6_integrate_motion_and_manifest(): void
    {
        // Seed starter motion components
        app(MotionLibrary::class)->seedStarterComponents();

        $cat = NewsCategory::create(['name' => 'AI', 'slug' => 'ai', 'is_active' => true]);
        $src = NewsSource::create(['name' => 'Source', 'slug' => 'src', 'base_url' => 'https://example.com', 'is_active' => true]);
        $news = News::create([
            'news_category_id' => $cat->id,
            'news_source_id' => $src->id,
            'title' => 'Autonomous Testing',
            'slug' => 'autonomous-testing',
            'body' => 'Body text',
            'status' => 'ready',
        ]);

        $script = VideoScript::create([
            'news_id' => $news->id,
            'title' => 'Autonomous Testing Script',
            'language' => 'en',
            'narration' => 'Spoken narration.',
            'target_duration' => 30,
            'status' => VideoScriptStatus::Approved,
            'scenes' => [
                ['id' => 's1', 'headline' => 'Scene 1', 'narration' => 'Narration 1', 'duration' => 5.0],
                ['id' => 's2', 'headline' => 'Scene 2', 'narration' => 'Narration 2', 'duration' => 5.0],
            ],
        ]);

        $run = PipelineRun::create([
            'run_id' => 'run_pipeline_test_'.uniqid(),
            'title' => 'End to End Pipeline Run',
            'status' => 'running',
            'current_step' => 5,
        ]);

        $service = app(PipelineStepService::class);

        // Run Step 5
        $step5 = $service->runStep5([
            'script_id' => $script->id,
            'motion_seed' => 4242,
        ], $run);

        $this->assertTrue($step5['success']);
        $this->assertNotEmpty($step5['artifacts']['motion_selections']);
        $this->assertNotEmpty($step5['artifacts']['motion_fingerprint']);

        // Check usages recorded in DB
        $this->assertDatabaseHas('motion_component_usages', [
            'pipeline_run_id' => $run->id,
            'seed' => 4242,
        ]);

        // Run Step 6 (sync)
        $step6 = $service->runStep6([
            'script_id' => $script->id,
            'backend' => 'hyperframes',
        ], $run);

        $this->assertTrue($step6['success']);
        $this->assertNotNull($step6['artifacts']['manifest_checksum']);

        // Check manifest recorded in DB
        $this->assertDatabaseHas('production_manifests', [
            'run_id' => $run->run_id,
            'status' => 'verified',
        ]);

        // Clean up
        File::deleteDirectory(storage_path("app/production/{$run->run_id}"));
    }
}

