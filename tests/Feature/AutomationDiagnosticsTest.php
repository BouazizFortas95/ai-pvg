<?php

namespace Tests\Feature;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationStageStatus;
use App\Enums\AutomationTrigger;
use App\Enums\ProviderHealthEventType;
use App\Models\AiUsageLedgerEntry;
use App\Models\ApprovalGate;
use App\Models\AutomationRun;
use App\Models\AutomationStageRun;
use App\Models\BudgetPeriod;
use App\Models\FeatureFlag;
use App\Models\News;
use App\Models\ProviderHealthEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        config([
            'automation.enabled' => false,
            'automation.dry_run' => true,
            'automation.approval.require_dual_public_publish' => false,
            'automation.budget.monthly_limit_micros' => 5_000_000,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_diagnostics_reports_read_only_automation_budget_and_approval_status(): void
    {
        $run = AutomationRun::create([
            'pipeline' => 'test_pipeline',
            'status' => AutomationRunStatus::Running,
            'dry_run' => true,
            'trigger' => AutomationTrigger::Scheduler,
            'started_at' => now(),
        ]);

        AutomationStageRun::create([
            'automation_run_id' => $run->getKey(),
            'stage' => 'score',
            'status' => AutomationStageStatus::Completed,
            'attempt' => 1,
            'max_attempts' => 3,
            'cost_micros' => 123,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        AiUsageLedgerEntry::create([
            'automation_run_id' => $run->getKey(),
            'provider' => 'ollama',
            'model' => 'llama3.2',
            'purpose' => 'score',
            'cost_micros' => 123,
        ]);

        BudgetPeriod::create([
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'limit_micros' => 5_000_000,
            'spent_micros' => 123,
            'reserved_micros' => 77,
            'breaker_state' => 'closed',
        ]);

        ApprovalGate::create([
            'gateable_type' => (new News)->getMorphClass(),
            'gateable_id' => News::factory()->create()->getKey(),
            'gate_type' => ApprovalGateType::PublicPublish,
            'status' => ApprovalGateStatus::Pending,
            'expires_at' => now()->addDay(),
        ]);

        ProviderHealthEvent::create([
            'provider' => 'openai',
            'event_type' => ProviderHealthEventType::RateLimit,
            'http_status' => 429,
            'retry_after_seconds' => 60,
            'message_sanitized' => 'Rate limited',
        ]);

        FeatureFlag::setFlag('automation.scoring', true);

        $this->artisan('ai-pvg:diagnostics')
            ->expectsOutputToContain('Automation enabled: NO (dry-run: YES)')
            ->expectsOutputToContain('Automation tables: 7/7 OK')
            ->expectsOutputToContain('Automation runs: total=1; pending=0; running=1; paused=0; completed=0; failed=0; cancelled=0')
            ->expectsOutputToContain('Automation stages: total=1')
            ->expectsOutputToContain('AI usage ledger: entries=1; cost_micros=123')
            ->expectsOutputToContain('Budget: period=2026-10-01; limit_micros=5000000; spent_micros=123; reserved_micros=77; remaining_micros=4999800; breaker=closed')
            ->expectsOutputToContain('Approvals: pending=1; approved=0; rejected=0; expired=0; dual_public_publish=NO')
            ->expectsOutputToContain('Provider health: events_24h=1; providers=openai=1; last=openai/rate_limit')
            ->expectsOutputToContain('Feature flags: total=1; enabled=1')
            ->expectsOutputToContain('youtube_publishing_states=0')
            ->assertSuccessful();
    }
}
