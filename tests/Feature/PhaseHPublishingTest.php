<?php

namespace Tests\Feature;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Enums\CircuitState;
use App\Filament\Resources\CoPilotSessions\CoPilotSessionResource;
use App\Filament\Resources\CoPilotSessions\Pages\ListCoPilotSessions;
use App\Filament\Resources\PublishingStates\Pages\ListPublishingStates;
use App\Filament\Resources\PublishingStates\PublishingStateResource;
use App\Livewire\StudioDashboard;
use App\Models\ApprovalGate;
use App\Models\BudgetPeriod;
use App\Models\CoPilotReviewSession;
use App\Models\FeatureFlag;
use App\Models\PublishingApprovalSession;
use App\Models\User;
use App\Models\VideoScript;
use App\Services\Automation\AutopilotGateService;
use App\Services\Publishing\CoPilotReviewService;
use App\Services\Publishing\YouTubePublishService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PhaseHPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'automation.publishing.enabled' => false,
            'automation.approval.require_dual_public_publish' => true,
            'automation.budget.monthly_limit_micros' => 5_000_000,
        ]);
    }

    public function test_publishing_gate_is_closed_by_default(): void
    {
        $video = VideoScript::factory()->create();
        [$first, $second] = $this->makeAdmins();
        $this->approvedSession($video, $first, $second);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Publishing is disabled');

        app(YouTubePublishService::class)->publishVideoScript($video->getKey());
    }

    public function test_public_publish_rejects_the_same_admin_twice(): void
    {
        $this->enablePublishing();
        $video = VideoScript::factory()->create();
        [$admin] = $this->makeAdmins(1);
        $this->approvedSession($video, $admin, $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('two distinct administrators');

        app(YouTubePublishService::class)->publishVideoScript($video->getKey());
    }

    public function test_public_publish_rejects_non_admin_approvers(): void
    {
        $this->enablePublishing();
        $video = VideoScript::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->approvedSession($video, $first, $second);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Both publishing approvers must be administrators');

        app(YouTubePublishService::class)->publishVideoScript($video->getKey());
    }

    public function test_public_publish_accepts_two_distinct_admin_session_approvers(): void
    {
        $this->enablePublishing();
        $video = VideoScript::factory()->create();
        [$first, $second] = $this->makeAdmins();
        $this->approvedSession($video, $first, $second);

        $this->assertTrue(app(YouTubePublishService::class)->publishVideoScript($video->getKey()));
    }

    public function test_public_publish_accepts_an_approved_public_publish_gate(): void
    {
        $this->enablePublishing();
        $video = VideoScript::factory()->create();
        [$first, $second] = $this->makeAdmins();

        ApprovalGate::query()->create([
            'gateable_type' => (new VideoScript)->getMorphClass(),
            'gateable_id' => $video->getKey(),
            'gate_type' => ApprovalGateType::PublicPublish,
            'status' => ApprovalGateStatus::Approved,
            'first_approver_id' => $first->getKey(),
            'second_approver_id' => $second->getKey(),
            'first_approved_at' => now(),
            'second_approved_at' => now(),
            'expires_at' => now()->addDay(),
            'meta' => ['require_dual' => true],
        ]);

        $this->assertTrue(app(YouTubePublishService::class)->publishVideoScript($video->getKey()));
    }

    public function test_autopilot_gate_is_blocked_by_default(): void
    {
        $snapshot = app(AutopilotGateService::class)->snapshot();

        $this->assertFalse($snapshot['allowed']);
        $this->assertContains('g01_automation', $snapshot['blocked']);
        $this->assertContains('g06_formats', $snapshot['blocked']);
    }

    public function test_autopilot_gate_requires_all_pipeline_flags(): void
    {
        $this->enableAutopilot();

        $gate = app(AutopilotGateService::class);
        $this->assertTrue($gate->evaluate());

        FeatureFlag::setFlag('assets.enabled', false);

        $snapshot = $gate->snapshot();
        $this->assertFalse($snapshot['allowed']);
        $this->assertContains('g05_assets', $snapshot['blocked']);
    }

    public function test_autopilot_gate_blocks_an_open_budget_breaker(): void
    {
        $this->enableAutopilot();

        BudgetPeriod::query()->create([
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'limit_micros' => 5_000_000,
            'spent_micros' => 0,
            'reserved_micros' => 0,
            'breaker_state' => CircuitState::Open,
            'opened_at' => now(),
            'reset_at' => now()->addHour(),
        ]);

        $snapshot = app(AutopilotGateService::class)->snapshot();

        $this->assertFalse($snapshot['allowed']);
        $this->assertContains('circuit_breaker_open', $snapshot['blocked']);
    }

    public function test_co_pilot_review_is_admin_only_and_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $regular = User::factory()->create();
        $service = app(CoPilotReviewService::class);

        $this->assertTrue($service->review($admin->getKey(), 42, CoPilotReviewSession::ACTION_APPROVE, 'Checked'));

        $this->assertDatabaseHas('co_pilot_review_sessions', [
            'user_id' => $admin->getKey(),
            'target_id' => 42,
            'action' => CoPilotReviewSession::ACTION_APPROVE,
            'status' => 'completed',
        ]);

        $this->expectException(AuthorizationException::class);
        $service->review($regular->getKey(), 43, CoPilotReviewSession::ACTION_REJECT);
    }

    public function test_phase_h_filament_resources_are_read_only_and_reachable(): void
    {
        $admin = User::factory()->admin()->create();
        $video = VideoScript::factory()->create();
        [$first, $second] = $this->makeAdmins();
        $session = $this->approvedSession($video, $first, $second);
        $review = CoPilotReviewSession::query()->create([
            'user_id' => $admin->getKey(),
            'target_type' => CoPilotReviewSession::TARGET_SCRIPT,
            'target_id' => $video->getKey(),
            'action' => CoPilotReviewSession::ACTION_APPROVE,
            'status' => 'completed',
        ]);

        $this->actingAs($admin);

        $this->assertFalse(PublishingStateResource::canCreate());
        $this->assertFalse(PublishingStateResource::canEdit($session));
        $this->assertFalse(PublishingStateResource::canDelete($session));
        $this->assertFalse(PublishingStateResource::canDeleteAny());
        $this->assertSame(['index', 'view'], array_keys(PublishingStateResource::getPages()));

        $this->assertFalse(CoPilotSessionResource::canCreate());
        $this->assertFalse(CoPilotSessionResource::canEdit($review));
        $this->assertFalse(CoPilotSessionResource::canDelete($review));
        $this->assertFalse(CoPilotSessionResource::canDeleteAny());
        $this->assertSame(['index', 'view'], array_keys(CoPilotSessionResource::getPages()));

        $this->get(PublishingStateResource::getUrl('index'))->assertSuccessful();
        $this->get(PublishingStateResource::getUrl('view', ['record' => $session]))->assertSuccessful();
        $this->get(CoPilotSessionResource::getUrl('index'))->assertSuccessful();
        $this->get(CoPilotSessionResource::getUrl('view', ['record' => $review]))->assertSuccessful();

        Livewire::test(ListPublishingStates::class)
            ->assertCanSeeTableRecords([$session])
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('firstApprover.name')
            ->assertTableColumnExists('secondApprover.name');

        Livewire::test(ListCoPilotSessions::class)
            ->assertCanSeeTableRecords([$review])
            ->assertTableColumnExists('user.name')
            ->assertTableColumnExists('action');
    }

    public function test_studio_shows_phase_h_oversight_without_publish_controls(): void
    {
        $admin = User::factory()->admin()->create();
        $video = VideoScript::factory()->create();
        [$first, $second] = $this->makeAdmins();
        $session = $this->approvedSession($video, $first, $second);
        CoPilotReviewSession::query()->create([
            'user_id' => $admin->getKey(),
            'target_type' => CoPilotReviewSession::TARGET_SCRIPT,
            'target_id' => $video->getKey(),
            'action' => CoPilotReviewSession::ACTION_APPROVE,
            'status' => 'completed',
        ]);

        $this->actingAs($admin);

        Livewire::test(StudioDashboard::class)
            ->assertSee('Publishing & Co-Pilot')
            ->assertSee('Approval Sessions')
            ->assertSee('Dual-Admin Approved')
            ->assertSee('Recent Publishing Approvals')
            ->assertSee($video->title)
            ->assertSee($first->name)
            ->assertSee($second->name)
            ->assertSee('Recent Co-Pilot Reviews')
            ->assertDontSee('Upload to YouTube')
            ->assertDontSee('Publish video');

        $this->assertDatabaseHas('publishing_approval_sessions', ['id' => $session->getKey()]);
    }

    public function test_phase_h_resources_fail_closed_when_tables_are_missing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Schema::dropIfExists('publishing_approval_sessions');
        Schema::dropIfExists('co_pilot_review_sessions');

        $this->assertFalse(PublishingStateResource::canAccess());
        $this->assertFalse(CoPilotSessionResource::canAccess());
    }

    /**
     * @return Collection<int, User>
     */
    private function makeAdmins(int $count = 2): Collection
    {
        return User::factory()->admin()->count($count)->create();
    }

    private function approvedSession(VideoScript $video, User $first, User $second): PublishingApprovalSession
    {
        return PublishingApprovalSession::query()->create([
            'video_script_id' => $video->getKey(),
            'status' => PublishingApprovalSession::STATUS_APPROVED,
            'first_approver_id' => $first->getKey(),
            'first_approved_at' => now(),
            'second_approver_id' => $second->getKey(),
            'second_approved_at' => now(),
        ]);
    }

    private function enablePublishing(): void
    {
        config(['automation.publishing.enabled' => true]);
        FeatureFlag::setFlag('publishing.enabled', true);
    }

    private function enableAutopilot(): void
    {
        config([
            'automation.enabled' => true,
            'automation.dry_run' => true,
            'automation.triage.enabled' => true,
            'automation.script_agents.enabled' => true,
            'automation.captions.enabled' => true,
            'automation.assets.enabled' => true,
            'automation.formats.enabled' => true,
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
}
