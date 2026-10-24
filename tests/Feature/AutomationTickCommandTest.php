<?php

namespace Tests\Feature;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Models\ApprovalGate;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationTickCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_enabled_tick_initializes_budget_and_expires_overdue_approvals(): void
    {
        config(['automation.enabled' => true]);

        $admin = User::factory()->admin()->create();
        $gate = ApprovalGate::create([
            'gateable_type' => (new News)->getMorphClass(),
            'gateable_id' => News::factory()->create()->getKey(),
            'gate_type' => ApprovalGateType::PaidProvider,
            'status' => ApprovalGateStatus::Pending,
            'requested_by' => $admin->getKey(),
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('ai-pvg:automation:tick')
            ->expectsOutputToContain('Automation tick complete')
            ->assertSuccessful();

        $this->assertDatabaseHas('budget_periods', [
            'period_start' => '2026-10-01',
            'spent_micros' => 0,
            'reserved_micros' => 0,
        ]);
        $this->assertDatabaseHas('approval_gates', [
            'id' => $gate->getKey(),
            'status' => ApprovalGateStatus::Expired->value,
        ]);
    }
}
