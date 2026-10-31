<?php

namespace Tests\Feature;

use App\Enums\NewsStatus;
use App\Filament\Pages\PipelineRunnerPage;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\NewsSource;
use App\Models\User;
use App\Models\VideoScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Feature tests for the Sequential Pipeline Runner Wizard Page.
 *
 * Enforces strict sequential step progression (Steps 1 → 6),
 * accurate progress bar calculation, and zero external publishing.
 */
class PipelineRunnerPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // ─── Access Control ───────────────────────────────────────────────────────

    public function test_page_is_accessible_by_admin(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(PipelineRunnerPage::getUrl())
            ->assertSuccessful();
    }

    public function test_page_redirects_unauthenticated_users(): void
    {
        $this->get(PipelineRunnerPage::getUrl())
            ->assertRedirectToRoute('filament.auth.auth.login');
    }

    public function test_page_is_forbidden_for_non_admin(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(PipelineRunnerPage::getUrl())
            ->assertForbidden();
    }

    public function test_can_access_returns_true_for_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->assertTrue(PipelineRunnerPage::canAccess());
    }

    public function test_can_access_returns_false_for_guest(): void
    {
        $this->assertFalse(PipelineRunnerPage::canAccess());
    }

    // ─── Wizard Layout & Rendering ───────────────────────────────────────────

    public function test_wizard_page_renders_with_initial_state(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->assertSee('AI-PVG Autonomous Pipeline Wizard')
            ->assertSee('Step 1: Prepare & Load Breaking News')
            ->assertSee('Step 2: Score News & Build Story Cluster')
            ->assertSee('Step 3: Generate AI Script, 3 Hooks & Fact-Check')
            ->assertSee('Step 4: Generate Voice, Word Captions & Audio Mix')
            ->assertSee('Step 5: Attach Visual B-Roll & Multi-Format Plans')
            ->assertSee('Step 6: Render Final MP4 Video Locally')
            ->assertSee('PUBLISHING LOCKED OFF')
            ->assertSet('currentStep', 1)
            ->assertSet('completedSteps', []);
    }

    public function test_page_renders_safely_with_empty_database(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->assertSuccessful();
    }

    // ─── Wizard Reset ─────────────────────────────────────────────────────────

    public function test_reset_wizard_restores_step_one_and_clears_state(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentStep', 4)
            ->set('completedSteps', [1, 2, 3])
            ->set('selectedClusterId', 42)
            ->set('selectedScriptId', 99)
            ->call('resetWizard')
            ->assertSet('currentStep', 1)
            ->assertSet('completedSteps', [])
            ->assertSet('selectedClusterId', null)
            ->assertSet('selectedScriptId', null)
            ->assertHasNoErrors();
    }

    // ─── Step 1: Prepare & Load Breaking News ─────────────────────────────────

    public function test_run_step_one_enables_local_flags_seeds_news_and_advances_to_step_two(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Artisan::shouldReceive('call')
            ->once()
            ->with('ai-pvg:seed-mock-cluster', [
                '--cluster-size' => 3,
                '--skip-triage' => true,
                '--skip-script' => true,
                '--skip-render' => true,
            ])
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('Mock cluster seeded successfully.');

        Livewire::test(PipelineRunnerPage::class)
            ->assertSet('currentStep', 1)
            ->call('runStep1')
            ->assertSet('currentStep', 2)
            ->assertSet('completedSteps', [1])
            ->assertHasNoErrors();

        if (Schema::hasTable('feature_flags')) {
            $this->assertTrue(FeatureFlag::isEnabled('triage.news_scoring_enabled'));
            $this->assertTrue(FeatureFlag::isEnabled('script_agents.enabled'));
            $this->assertTrue(FeatureFlag::isEnabled('captions.enabled'));
            $this->assertTrue(FeatureFlag::isEnabled('assets.enabled'));
            $this->assertTrue(FeatureFlag::isEnabled('formats.enabled'));
            $this->assertFalse(FeatureFlag::isEnabled('publishing.enabled'));
        }

        $this->assertFalse((bool) config('automation.publishing.enabled'));
    }

    // ─── Step 2: Score News & Build Story Cluster ─────────────────────────────

    public function test_run_step_two_clusters_news_selects_cluster_and_advances(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentStep', 2)
            ->call('runStep2')
            ->assertSet('currentStep', 3)
            ->assertSet('completedSteps', [2])
            ->assertHasNoErrors();
    }

    // ─── Step 3: Generate AI Script, 3 Hooks & Fact-Check ─────────────────────

    public function test_run_step_three_advances_to_step_four(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        if (Schema::hasTable('news')) {
            News::factory()->create(['status' => NewsStatus::Ready->value]);
        }

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentStep', 3)
            ->call('runStep3')
            ->assertSet('currentStep', 4)
            ->assertSet('completedSteps', [3])
            ->assertHasNoErrors();
    }

    // ─── Step 4: Generate Voice, Word Captions & Audio Mix ───────────────────

    public function test_run_step_four_without_script_warns_gracefully(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentStep', 4)
            ->call('runStep4')
            ->assertHasNoErrors();
    }

    // ─── Step 5: Attach Visual B-Roll & Multi-Format Plans ───────────────────

    public function test_run_step_five_without_script_warns_gracefully(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentStep', 5)
            ->call('runStep5')
            ->assertHasNoErrors();
    }

    // ─── Step 6: Render Final MP4 Video Locally ───────────────────────────────

    public function test_run_step_six_executes_verify_mp4_and_keeps_publishing_locked(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Artisan::shouldReceive('call')
            ->once()
            ->with('ai-pvg:verify-mp4', ['--execute' => true])
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('MP4 rendered and verified.');

        Livewire::test(PipelineRunnerPage::class)
            ->set('currentStep', 6)
            ->call('runStep6')
            ->assertSet('completedSteps', [6])
            ->assertHasNoErrors();

        $this->assertFalse((bool) config('automation.publishing.enabled'));
    }

    // ─── Sequential Walkthrough & Progress Percentage ─────────────────────────

    public function test_sequential_step_progression_updates_progress_accurately(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $component = Livewire::test(PipelineRunnerPage::class);
        $this->assertSame(0, $component->instance()->progressPercent());

        $component->set('completedSteps', [1]);
        $this->assertSame(17, $component->instance()->progressPercent());

        $component->set('completedSteps', [1, 2]);
        $this->assertSame(33, $component->instance()->progressPercent());

        $component->set('completedSteps', [1, 2, 3]);
        $this->assertSame(50, $component->instance()->progressPercent());

        $component->set('completedSteps', [1, 2, 3, 4]);
        $this->assertSame(67, $component->instance()->progressPercent());

        $component->set('completedSteps', [1, 2, 3, 4, 5]);
        $this->assertSame(83, $component->instance()->progressPercent());

        $component->set('completedSteps', [1, 2, 3, 4, 5, 6]);
        $this->assertSame(100, $component->instance()->progressPercent());
    }

    public function test_step_lock_and_active_helpers(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $component = Livewire::test(PipelineRunnerPage::class)
            ->set('currentStep', 3)
            ->set('completedSteps', [1, 2]);

        /** @var PipelineRunnerPage $page */
        $page = $component->instance();

        $this->assertTrue($page->isStepCompleted(1));
        $this->assertTrue($page->isStepCompleted(2));
        $this->assertFalse($page->isStepCompleted(3));

        $this->assertTrue($page->isStepActive(3));
        $this->assertFalse($page->isStepActive(4));

        $this->assertFalse($page->isStepLocked(1));
        $this->assertFalse($page->isStepLocked(2));
        $this->assertFalse($page->isStepLocked(3));
        $this->assertTrue($page->isStepLocked(4));
        $this->assertTrue($page->isStepLocked(5));
        $this->assertTrue($page->isStepLocked(6));
    }

    // ─── Metric Helpers Safety ───────────────────────────────────────────────

    public function test_metrics_helpers_are_safe_with_empty_database(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $component = Livewire::test(PipelineRunnerPage::class);

        /** @var PipelineRunnerPage $page */
        $page = $component->instance();

        $this->assertIsArray($page->step1Metrics());
        $this->assertIsArray($page->step2Metrics());
        $this->assertIsArray($page->step3Metrics());
        $this->assertIsArray($page->step4Metrics());
        $this->assertIsArray($page->step5Metrics());
        $this->assertIsArray($page->step6Metrics());
    }

    // ─── Full Sequential Step Progression 1 → 6 ──────────────────────────────

    public function test_full_sequential_step_progression_one_through_six_in_order(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        // Preflight mock for step 1 & step 6
        Artisan::shouldReceive('call')
            ->with('ai-pvg:seed-mock-cluster', [
                '--cluster-size' => 3,
                '--skip-triage' => true,
                '--skip-script' => true,
                '--skip-render' => true,
            ])
            ->andReturn(0);

        Artisan::shouldReceive('call')
            ->with('ai-pvg:verify-mp4', ['--execute' => true])
            ->andReturn(0);

        Artisan::shouldReceive('output')->andReturn('Executed successfully.');

        // Seed news & cluster so pipeline steps have data
        $news = News::factory()->create(['status' => NewsStatus::Ready->value]);
        $script = VideoScript::factory()->create(['news_id' => $news->getKey()]);

        $test = Livewire::test(PipelineRunnerPage::class)
            ->assertSet('currentStep', 1)
            ->assertSet('completedSteps', [])
            // Step 1
            ->call('runStep1')
            ->assertSet('currentStep', 2)
            ->assertSet('completedSteps', [1])
            // Step 2
            ->call('runStep2')
            ->assertSet('currentStep', 3)
            ->assertSet('completedSteps', [1, 2])
            // Step 3
            ->call('runStep3')
            ->assertSet('currentStep', 4)
            ->assertSet('completedSteps', [1, 2, 3])
            // Step 4
            ->call('runStep4')
            ->assertSet('currentStep', 5)
            ->assertSet('completedSteps', [1, 2, 3, 4])
            // Step 5
            ->call('runStep5')
            ->assertSet('currentStep', 6)
            ->assertSet('completedSteps', [1, 2, 3, 4, 5])
            // Step 6
            ->call('runStep6')
            ->assertSet('completedSteps', [1, 2, 3, 4, 5, 6]);

        $this->assertSame(100, $test->instance()->progressPercent());
        $this->assertFalse((bool) config('automation.publishing.enabled'));
    }

    public function test_toggle_unlock_all_steps_allows_access_to_all_steps(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $test = Livewire::test(PipelineRunnerPage::class)
            ->assertSet('unlockAllSteps', false);

        /** @var PipelineRunnerPage $page */
        $page = $test->instance();
        $this->assertTrue($page->isStepLocked(4));

        $test->call('toggleUnlockAllSteps')
            ->assertSet('unlockAllSteps', true);

        $this->assertFalse($test->instance()->isStepLocked(4));
    }

    public function test_preflight_check_action_executes_verify_mp4(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Artisan::shouldReceive('call')
            ->once()
            ->with('ai-pvg:verify-mp4')
            ->andReturn(0);

        Artisan::shouldReceive('output')->andReturn('Preflight verified.');

        Livewire::test(PipelineRunnerPage::class)
            ->call('runPreflight')
            ->assertHasNoErrors();
    }

    // ─── Backward-Compatibility Aliases ───────────────────────────────────────

    public function test_backward_compatibility_aliases_work_safely(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->call('enableAllLocalFlags')
            ->call('runTriageAndCluster')
            ->call('runScriptPipeline')
            ->call('runVoiceAndCaptions')
            ->call('runHarvestAssets')
            ->call('runMultiFormat')
            ->call('runCoPilotApprove')
            ->assertHasNoErrors();
    }

    public function test_navigation_badge_shows_ready_news_count(): void
    {
        if (! Schema::hasTable('news') || ! Schema::hasTable('news_sources')) {
            $this->assertTrue(true, 'Tables unavailable');

            return;
        }

        $source = NewsSource::factory()->create(['is_active' => true]);

        News::factory()->count(3)->create([
            'news_source_id' => $source->getKey(),
            'status' => NewsStatus::Ready->value,
        ]);

        $badge = PipelineRunnerPage::getNavigationBadge();
        $this->assertSame('3', $badge);
    }

    public function test_changing_duration_updates_state_without_error(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->set('step3Duration', '')
            ->assertSet('step3Duration', null)
            ->assertHasNoErrors()
            ->set('step3Duration', '60')
            ->assertSet('step3Duration', 60)
            ->assertHasNoErrors()
            ->set('step3Duration', 30)
            ->assertSet('step3Duration', 30)
            ->assertHasNoErrors()
            ->set('step3Duration', 5) // Below minimum 10s
            ->assertSet('step3Duration', 10)
            ->assertHasNoErrors()
            ->set('step3Duration', 300) // Above maximum 180s
            ->assertSet('step3Duration', 180)
            ->assertHasNoErrors();

        // Sequential run with custom duration
        $component = Livewire::test(PipelineRunnerPage::class)
            ->call('runStep1')
            ->call('runStep2')
            ->set('step3Duration', 30)
            ->call('runStep3')
            ->assertHasNoErrors();

        $script = VideoScript::query()->latest()->first();
        $this->assertNotNull($script);
        $this->assertSame(30, (int) $script->target_duration);
        $this->assertSame(4, $component->get('currentStep'));
    }

    public function test_changing_similarity_threshold_updates_state_without_error(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(PipelineRunnerPage::class)
            ->set('step2SimilarityThreshold', '')
            ->assertSet('step2SimilarityThreshold', null)
            ->assertHasNoErrors()
            ->set('step2SimilarityThreshold', '0.5')
            ->assertSet('step2SimilarityThreshold', 0.5)
            ->assertHasNoErrors()
            ->set('step2SimilarityThreshold', 0.75)
            ->assertSet('step2SimilarityThreshold', 0.75)
            ->assertHasNoErrors()
            ->set('step2SimilarityThreshold', 0.01) // Below min 0.1
            ->assertSet('step2SimilarityThreshold', 0.1)
            ->assertHasNoErrors()
            ->set('step2SimilarityThreshold', 1.5) // Above max 0.95
            ->assertSet('step2SimilarityThreshold', 0.95)
            ->assertHasNoErrors();
    }
}
