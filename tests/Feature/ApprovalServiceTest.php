<?php

namespace Tests\Feature;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Enums\UserRole;
use App\Events\Automation\ApprovalDecided;
use App\Events\Automation\ApprovalRequested;
use App\Exceptions\Automation\ApprovalException;
use App\Models\News;
use App\Models\User;
use App\Services\Automation\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        config([
            'automation.approval.require_dual_public_publish' => true,
            'automation.approval.ttl_hours' => 72,
            'automation.approval.system_user_id' => null,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_admins_can_approve(): void
    {
        $requester = $this->admin();
        $regularUser = User::factory()->create();
        $news = News::factory()->create();

        $gate = app(ApprovalService::class)->request($news, ApprovalGateType::PaidProvider, $requester);

        $this->expectException(AuthorizationException::class);

        app(ApprovalService::class)->approve($gate, $regularUser);
    }

    public function test_public_publish_requires_two_distinct_admins_and_records_both_audit_steps(): void
    {
        $requester = $this->admin();
        $first = $this->admin();
        $second = $this->admin();
        $news = News::factory()->create();
        $service = app(ApprovalService::class);

        $gate = $service->request($news, ApprovalGateType::PublicPublish, $requester);

        $this->assertTrue($service->requiresDualApproval($gate));
        $this->assertTrue((bool) ($gate->meta['require_dual'] ?? false));

        $afterFirst = $service->approve($gate, $first, 'Fact sheet checked');

        $this->assertSame(ApprovalGateStatus::Pending, $afterFirst->status);
        $this->assertSame($first->getKey(), $afterFirst->first_approver_id);
        $this->assertNotNull($afterFirst->first_approved_at);
        $this->assertNull($afterFirst->second_approver_id);
        $this->assertNull($afterFirst->second_approved_at);
        $this->assertSame('Fact sheet checked', $afterFirst->meta['first_approval_note']);

        try {
            $service->approve($gate, $first, 'Trying again');
            $this->fail('The same admin must not satisfy both approval steps.');
        } catch (ApprovalException $exception) {
            $this->assertStringContainsString('two distinct administrators', $exception->getMessage());
        }

        $final = $service->approve($gate, $second, 'Publish approved');

        $this->assertSame(ApprovalGateStatus::Approved, $final->status);
        $this->assertSame($first->getKey(), $final->first_approver_id);
        $this->assertSame($second->getKey(), $final->second_approver_id);
        $this->assertNotNull($final->first_approved_at);
        $this->assertNotNull($final->second_approved_at);
        $this->assertSame($second->getKey(), $final->decided_by);
        $this->assertSame('Publish approved', $final->decision_note);

        $this->assertDatabaseHas('approval_gates', [
            'id' => $final->getKey(),
            'status' => ApprovalGateStatus::Approved->value,
            'first_approver_id' => $first->getKey(),
            'second_approver_id' => $second->getKey(),
        ]);
    }

    public function test_configured_system_user_cannot_approve_a_human_initiated_request(): void
    {
        $system = $this->admin();
        $requester = $this->admin();
        $news = News::factory()->create();

        config(['automation.approval.system_user_id' => $system->getKey()]);

        $gate = app(ApprovalService::class)->request($news, ApprovalGateType::PublicPublish, $requester);

        $this->expectException(ApprovalException::class);
        $this->expectExceptionMessage('system context cannot approve');

        app(ApprovalService::class)->approve($gate, $system);
    }

    public function test_single_approval_gate_approves_with_one_admin(): void
    {
        config(['automation.approval.require_dual_public_publish' => false]);

        $admin = $this->admin();
        $news = News::factory()->create();
        $service = app(ApprovalService::class);

        $gate = $service->request($news, ApprovalGateType::PublicPublish, $this->admin());
        $approved = $service->approve($gate, $admin, 'Single approval');

        $this->assertSame(ApprovalGateStatus::Approved, $approved->status);
        $this->assertSame($admin->getKey(), $approved->first_approver_id);
        $this->assertNull($approved->second_approver_id);
        $this->assertSame($admin->getKey(), $approved->decided_by);
    }

    public function test_expired_gate_cannot_be_approved(): void
    {
        $admin = $this->admin();
        $news = News::factory()->create();
        $service = app(ApprovalService::class);

        $gate = $service->request(
            $news,
            ApprovalGateType::PaidProvider,
            $this->admin(),
            now()->subMinute(),
        );

        try {
            $service->approve($gate, $admin);
            $this->fail('An expired approval gate should not be approvable.');
        } catch (ApprovalException $exception) {
            $this->assertStringContainsString('expired', $exception->getMessage());
        }

        $this->assertDatabaseHas('approval_gates', [
            'id' => $gate->getKey(),
            'status' => ApprovalGateStatus::Expired->value,
        ]);
    }

    public function test_rejection_requires_a_reason_and_is_audited(): void
    {
        $admin = $this->admin();
        $news = News::factory()->create();
        $service = app(ApprovalService::class);
        $gate = $service->request($news, ApprovalGateType::PaidProvider, $admin);

        try {
            $service->reject($gate, $admin, '   ');
            $this->fail('A blank rejection reason should be rejected.');
        } catch (ApprovalException $exception) {
            $this->assertStringContainsString('reason is required', $exception->getMessage());
        }

        $rejected = $service->reject($gate, $admin, 'Cost is too high');

        $this->assertSame(ApprovalGateStatus::Rejected, $rejected->status);
        $this->assertSame($admin->getKey(), $rejected->decided_by);
        $this->assertSame('Cost is too high', $rejected->decision_note);
    }

    public function test_pending_requests_are_idempotent_and_emit_events(): void
    {
        Event::fake([ApprovalRequested::class, ApprovalDecided::class]);

        $admin = $this->admin();
        $news = News::factory()->create();
        $service = app(ApprovalService::class);

        $first = $service->request($news, ApprovalGateType::PaidProvider, $admin);
        $second = $service->request($news, ApprovalGateType::PaidProvider, $admin);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertDatabaseCount('approval_gates', 1);
        Event::assertDispatched(ApprovalRequested::class);

        $service->approve($first, $admin, 'Approved');
        Event::assertDispatched(ApprovalDecided::class);
    }

    public function test_expire_pending_updates_only_overdue_gates(): void
    {
        $admin = $this->admin();
        $news = News::factory()->create();
        $service = app(ApprovalService::class);

        $overdue = $service->request($news, ApprovalGateType::PaidProvider, $admin, now()->subHour());
        $future = $service->request(News::factory()->create(), ApprovalGateType::PaidProvider, $admin, now()->addHour());

        $count = $service->expirePending();

        $this->assertSame(1, $count);
        $this->assertSame(ApprovalGateStatus::Expired, $overdue->fresh()->status);
        $this->assertSame(ApprovalGateStatus::Pending, $future->fresh()->status);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        $this->assertSame(UserRole::Admin, $admin->role);

        return $admin;
    }
}
