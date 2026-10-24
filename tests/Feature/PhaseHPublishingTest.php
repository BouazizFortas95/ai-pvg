<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseHPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_dual_admin_gate_requires_distinct_approvers(): void
    {
        $video = \App\Models\VideoScript::factory()->create();
        $service = new \App\Services\Publishing\YouTubePublishService();
        \App\Models\PublishingApprovalSession::create([
            'video_script_id' => $video->id,
            'status' => 'approved',
            'first_approver_id' => 1,
            'second_approver_id' => 1,
        ]);
        $this->expectException(\RuntimeException::class);
        $service->publishVideoScript($video->id);
    }

    public function test_autopilot_gate_blocked_by_default(): void
    {
        $gate = new \App\Services\Automation\AutopilotGateService();
        $this->assertFalse($gate->evaluate());
    }

    public function test_co_pilot_review_creates_session(): void
    {
        $user = \App\Models\User::factory()->create();
        $service = new \App\Services\Publishing\CoPilotReviewService();
        $this->assertTrue($service->review($user->id, 1, 'approve'));
        $this->assertDatabaseHas('co_pilot_review_sessions', [
            'user_id' => $user->id, 'target_id' => 1, 'action' => 'approve',
        ]);
    }
}
