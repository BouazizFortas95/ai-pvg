<?php

namespace Tests\Feature;

use App\Enums\MotionCategory;
use App\Enums\MotionComponentStatus;
use App\Enums\MotionLicenseStatus;
use App\Exceptions\Motion\MotionValidationException;
use App\Models\MotionComponent;
use App\Models\MotionRecipe;
use App\Models\MotionSource;
use App\Models\PipelineRun;
use App\Services\Motion\MotionComponentCompiler;
use App\Services\Motion\MotionComponentValidator;
use App\Services\Motion\MotionLibrary;
use App\Services\Motion\MotionSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MotionCompilerAndSelectorTest extends TestCase
{
    use RefreshDatabase;

    protected MotionComponentValidator $validator;

    protected MotionLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new MotionComponentValidator;
        $this->library = new MotionLibrary($this->validator);
    }

    public function test_validator_rejects_network_and_non_deterministic_apis(): void
    {
        // 1. Network API check
        $networkComp = [
            'component_key' => 'bad_network_comp',
            'category' => MotionCategory::LowerThird->value,
            'html_template' => '<div></div>',
            'gsap_script' => 'fetch("https://api.example.com/data")',
            'supported_formats' => ['9:16'],
            'contrast_rating' => 6.0,
        ];

        $res1 = $this->validator->validate($networkComp);
        $this->assertFalse($res1['valid']);
        $this->assertStringContainsString('Forbidden network API', implode(' ', $res1['errors']));

        // 2. Math.random check
        $randomComp = [
            'component_key' => 'bad_random_comp',
            'category' => MotionCategory::LowerThird->value,
            'html_template' => '<div></div>',
            'gsap_script' => 'const offset = Math.random() * 10;',
            'supported_formats' => ['9:16'],
            'contrast_rating' => 6.0,
        ];

        $res2 = $this->validator->validate($randomComp);
        $this->assertFalse($res2['valid']);
        $this->assertStringContainsString('Math.random() is forbidden', implode(' ', $res2['errors']));

        // 3. setTimeout check
        $timerComp = [
            'component_key' => 'bad_timer_comp',
            'category' => MotionCategory::LowerThird->value,
            'html_template' => '<div></div>',
            'gsap_script' => 'setTimeout(() => console.log("done"), 1000);',
            'supported_formats' => ['9:16'],
            'contrast_rating' => 6.0,
        ];

        $res3 = $this->validator->validate($timerComp);
        $this->assertFalse($res3['valid']);
        $this->assertStringContainsString('setTimeout() is forbidden', implode(' ', $res3['errors']));

        // 4. Contrast check
        $contrastComp = [
            'component_key' => 'bad_contrast_comp',
            'category' => MotionCategory::LowerThird->value,
            'html_template' => '<div></div>',
            'gsap_script' => 'const tl = gsap.timeline();',
            'supported_formats' => ['9:16'],
            'contrast_rating' => 2.5, // Below 4.5
        ];

        $this->expectException(MotionValidationException::class);
        $this->validator->assertValid($contrastComp);
    }

    public function test_motion_library_starter_components_are_all_valid_and_original(): void
    {
        $count = $this->library->seedStarterComponents();
        $this->assertEquals(13, $count);

        $components = MotionComponent::all();
        $this->assertCount(13, $components);

        foreach ($components as $component) {
            $this->assertEquals(MotionLicenseStatus::Original, $component->license_status);
            $this->assertEquals(MotionComponentStatus::Approved, $component->status);
            $this->assertTrue($component->isRenderable());
            $this->assertGreaterThanOrEqual(4.5, $component->contrast_rating);
        }

        // Verify distinct categories
        $categories = $components->pluck('category')->unique()->all();
        $this->assertCount(13, $categories);
    }

    public function test_compiler_authors_component_with_reference_only_by_default(): void
    {
        $source = MotionSource::create([
            'source_url' => 'https://promptmotion.org/item/test',
            'creator_handle' => 'creator_a',
            'site_name' => 'PromptMotion',
            'license_status' => MotionLicenseStatus::ReferenceOnly,
            'attribution_text' => 'Inspired by @creator_a',
        ]);

        $recipe = MotionRecipe::create([
            'motion_source_id' => $source->id,
            'title' => 'Floating Glass Card',
            'prompt_text' => 'Animate a translucent glass card sliding from bottom with blur',
            'category' => MotionCategory::QuoteCard->value,
            'license_status' => MotionLicenseStatus::ReferenceOnly,
        ]);

        $compiler = new MotionComponentCompiler($this->validator);
        $compiled = $compiler->compileFromRecipe($recipe, ['dry_run' => true]);

        $this->assertInstanceOf(MotionComponent::class, $compiled);
        $this->assertEquals(MotionComponentStatus::PendingReview, $compiled->status);
        $this->assertEquals(MotionLicenseStatus::ReferenceOnly, $compiled->license_status);
        $this->assertFalse($compiled->isRenderable(), 'Unapproved / reference-only components must not be renderable');
    }

    public function test_motion_selector_is_deterministic_with_prng_seed(): void
    {
        $this->library->seedStarterComponents();

        $scenes = [
            ['id' => 's1', 'headline' => 'Opening Scene'],
            ['id' => 's2', 'headline' => 'Second Scene Details'],
            ['id' => 's3', 'headline' => 'Final Summary Scene'],
        ];

        $selector1 = new MotionSelector(seed: 42, tone: 'normal', format: '9:16');
        $run1 = $selector1->selectForScenes($scenes);

        $selector2 = new MotionSelector(seed: 42, tone: 'normal', format: '9:16');
        $run2 = $selector2->selectForScenes($scenes);

        // Same seed must produce bit-for-bit identical fingerprints and component selections
        $this->assertEquals($run1['fingerprint'], $run2['fingerprint']);
        $this->assertEquals($run1['selections'], $run2['selections']);

        // Different seed produces different fingerprint
        $selectorDiff = new MotionSelector(seed: 9999, tone: 'normal', format: '9:16');
        $runDiff = $selectorDiff->selectForScenes($scenes);
        $this->assertNotEquals($run1['fingerprint'], $runDiff['fingerprint']);
    }

    public function test_motion_selector_excludes_reference_only_components(): void
    {
        // Add an approved component that is reference_only
        MotionComponent::create([
            'component_key' => 'unlicensed_lower_third',
            'version' => 1,
            'category' => MotionCategory::HeadlineBar->value,
            'html_template' => '<div></div>',
            'gsap_script' => 'const tl = gsap.timeline();',
            'params_schema' => [],
            'supported_formats' => ['9:16'],
            'tone_compatibility' => ['normal'],
            'contrast_rating' => 7.0,
            'license_status' => MotionLicenseStatus::ReferenceOnly,
            'status' => MotionComponentStatus::Approved,
        ]);

        $this->library->seedStarterComponents();

        $selector = new MotionSelector(seed: 12345, tone: 'normal', format: '9:16');
        $result = $selector->selectForScenes([
            ['id' => 's1', 'headline' => 'Headline Scene'],
        ]);

        $selectedKey = $result['selections'][0]['component_key'];
        $this->assertNotEquals('unlicensed_lower_third', $selectedKey);
        $this->assertNotEquals('reference_only', $result['selections'][0]['license_status']);
    }

    public function test_motion_selector_respects_user_locks_and_records_usages(): void
    {
        $this->library->seedStarterComponents();

        $pipelineRun = PipelineRun::create([
            'run_id' => 'run_test_motion_123',
            'title' => 'Test Motion Selector Run',
            'status' => 'running',
            'current_step' => 5,
        ]);

        $lockedKey = 'starter_quote_card_glass';

        $selector = new MotionSelector(
            seed: 555,
            tone: 'normal',
            format: '9:16',
            locks: [MotionCategory::LowerThird->value => $lockedKey],
        );

        $scenes = [
            ['id' => 's1', 'directives' => ['motion_slot' => MotionCategory::LowerThird->value], 'headline' => 'Locked Slot'],
        ];

        $result = $selector->selectForScenes($scenes, $pipelineRun);

        $this->assertCount(1, $result['selections']);
        $this->assertEquals($lockedKey, $result['selections'][0]['component_key']);

        // Check usage record in database
        $this->assertDatabaseHas('motion_component_usages', [
            'pipeline_run_id' => $pipelineRun->id,
            'scene_index' => 1,
            'seed' => 555,
        ]);
    }
}
