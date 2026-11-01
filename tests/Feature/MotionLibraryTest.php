<?php

namespace Tests\Feature;

use App\Enums\MotionCategory;
use App\Enums\MotionComponentStatus;
use App\Enums\MotionLicenseStatus;
use App\Enums\MotionRecipeStatus;
use App\Models\MotionComponent;
use App\Models\MotionRecipe;
use App\Models\MotionSource;
use App\Models\User;
use App\Services\Motion\MotionRecipeImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class MotionLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected MotionRecipeImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new MotionRecipeImporter;
    }

    public function test_motion_library_tables_exist_in_database(): void
    {
        $this->assertTrue(Schema::hasTable('motion_sources'));
        $this->assertTrue(Schema::hasTable('motion_recipes'));
        $this->assertTrue(Schema::hasTable('motion_components'));
        $this->assertTrue(Schema::hasTable('motion_component_usages'));
    }

    public function test_recipe_importer_defaults_to_reference_only_licensing(): void
    {
        $recipe = $this->importer->import([
            'title' => 'Cyber Glow Lower Third',
            'prompt_text' => 'Animate a glowing cyan bar with sliding text and subtle pulse',
            'source_url' => 'https://promptmotion.org/recipe/cyber-lower-third',
            'creator_handle' => 'motion_crafter',
            'category' => 'lower_third',
        ]);

        $this->assertInstanceOf(MotionRecipe::class, $recipe);
        $this->assertEquals(MotionLicenseStatus::ReferenceOnly, $recipe->license_status);
        $this->assertEquals(MotionRecipeStatus::Imported, $recipe->status);
        $this->assertEquals(MotionCategory::LowerThird->value, $recipe->category);

        $source = $recipe->source;
        $this->assertInstanceOf(MotionSource::class, $source);
        $this->assertEquals('motion_crafter', $source->creator_handle);
        $this->assertEquals(MotionLicenseStatus::ReferenceOnly, $source->license_status);
        $this->assertStringContainsString('@motion_crafter', $source->attribution_text);
    }

    public function test_recipe_importer_sanitizes_prompt_text_as_pure_data(): void
    {
        $taintedPrompt = "High-tech kinetic title \x00\x07\x1B[31mwith control characters";
        $recipe = $this->importer->import([
            'title' => 'Kinetic Title',
            'prompt_text' => $taintedPrompt,
            'source_url' => 'https://promptmotion.org/recipe/kinetic-title',
            'creator_handle' => 'animator_x',
        ]);

        $this->assertStringNotContainsString("\x00", $recipe->prompt_text);
        $this->assertStringNotContainsString("\x07", $recipe->prompt_text);
        $this->assertStringContainsString('High-tech kinetic title', $recipe->prompt_text);
    }

    public function test_recipe_importer_rejects_missing_url_or_creator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid source URL is required');

        $this->importer->import([
            'title' => 'Broken Recipe',
            'prompt_text' => 'Some animation description',
            'source_url' => 'not-a-valid-url',
            'creator_handle' => 'anon',
        ]);
    }

    public function test_motion_component_licensing_truth_taxonomy(): void
    {
        $user = User::factory()->create();

        // 1. Reference Only: CANNOT be rendered even if approved
        $refComponent = MotionComponent::create([
            'component_key' => 'ref_lower_third_v1',
            'version' => 1,
            'category' => MotionCategory::LowerThird->value,
            'html_template' => '<div class="lower-third"></div>',
            'gsap_script' => 'gsap.from(".lower-third", {x: -100});',
            'params_schema' => ['title' => 'string'],
            'supported_formats' => ['9:16', '16:9'],
            'tone_compatibility' => ['normal'],
            'contrast_rating' => 5.2,
            'license_status' => MotionLicenseStatus::ReferenceOnly,
            'status' => MotionComponentStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        $this->assertFalse($refComponent->isRenderable(), 'Reference-only components must NEVER be renderable into real output');

        // 2. Pending Review: CANNOT be rendered even if cleared
        $pendingComponent = MotionComponent::create([
            'component_key' => 'cleared_pending_v1',
            'version' => 1,
            'category' => MotionCategory::HeadlineBar->value,
            'html_template' => '<div class="headline"></div>',
            'gsap_script' => 'gsap.from(".headline", {opacity: 0});',
            'params_schema' => [],
            'supported_formats' => ['9:16'],
            'tone_compatibility' => ['normal'],
            'contrast_rating' => 4.8,
            'license_status' => MotionLicenseStatus::Cleared,
            'status' => MotionComponentStatus::PendingReview,
        ]);

        $this->assertFalse($pendingComponent->isRenderable(), 'Pending review components cannot be rendered');

        // 3. Approved + Cleared: CAN be rendered
        $clearedApproved = MotionComponent::create([
            'component_key' => 'cleared_approved_v1',
            'version' => 1,
            'category' => MotionCategory::Ticker->value,
            'html_template' => '<div class="ticker"></div>',
            'gsap_script' => 'gsap.to(".ticker", {xPercent: -100});',
            'params_schema' => [],
            'supported_formats' => ['9:16', '16:9', '1:1'],
            'tone_compatibility' => ['normal', 'sensitive'],
            'contrast_rating' => 7.1,
            'license_status' => MotionLicenseStatus::Cleared,
            'status' => MotionComponentStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        $this->assertTrue($clearedApproved->isRenderable());

        // 4. Approved + Original In-House: CAN be rendered
        $originalApproved = MotionComponent::create([
            'component_key' => 'original_stat_v1',
            'version' => 1,
            'category' => MotionCategory::StatCounter->value,
            'html_template' => '<div class="stat"></div>',
            'gsap_script' => 'gsap.from(".stat", {scale: 0});',
            'params_schema' => [],
            'supported_formats' => ['9:16'],
            'tone_compatibility' => ['normal'],
            'contrast_rating' => 6.0,
            'license_status' => MotionLicenseStatus::Original,
            'status' => MotionComponentStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        $this->assertTrue($originalApproved->isRenderable());
    }

    public function test_domain_allowlist_enforcement(): void
    {
        $this->assertTrue($this->importer->isAllowlisted('https://promptmotion.org/item/123'));
        $this->assertTrue($this->importer->isAllowlisted('https://showheyohtaki.github.io/motion-prompt/'));
        $this->assertTrue($this->importer->isAllowlisted('https://promptmotion.art/'));
        $this->assertFalse($this->importer->isAllowlisted('https://untrusted-pirate-site.com/recipe'));
        $this->assertFalse($this->importer->isAllowlisted('https://google.com'));
    }
}

