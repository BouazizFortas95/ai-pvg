<?php

namespace Tests\Feature;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTrigger;
use App\Filament\Resources\ApprovalGates\ApprovalGateResource;
use App\Filament\Resources\ApprovalGates\Pages\ListApprovalGates;
use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use App\Filament\Resources\AutomationRuns\Pages\ListAutomationRuns;
use App\Models\ApprovalGate;
use App\Models\AutomationRun;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationFilamentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_automation_run_resource_is_read_only_and_reachable(): void
    {
        $run = $this->makeRun();

        $this->assertFalse(AutomationRunResource::canCreate());
        $this->assertFalse(AutomationRunResource::canEdit($run));
        $this->assertFalse(AutomationRunResource::canDelete($run));
        $this->assertFalse(AutomationRunResource::canDeleteAny());
        $this->assertFalse(AutomationRunResource::hasPage('create'));
        $this->assertFalse(AutomationRunResource::hasPage('edit'));
        $this->assertSame(['index', 'view'], array_keys(AutomationRunResource::getPages()));

        $this->get(AutomationRunResource::getUrl('index'))->assertSuccessful();
        $this->get(AutomationRunResource::getUrl('view', ['record' => $run]))->assertSuccessful();

        Livewire::test(ListAutomationRuns::class)
            ->assertCanSeeTableRecords([$run])
            ->assertTableColumnExists('pipeline')
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('dry_run')
            ->assertTableColumnExists('budget_spent_micros');
    }

    public function test_approval_gate_resource_is_read_only_and_reachable(): void
    {
        $gate = $this->makeGate();

        $this->assertFalse(ApprovalGateResource::canCreate());
        $this->assertFalse(ApprovalGateResource::canEdit($gate));
        $this->assertFalse(ApprovalGateResource::canDelete($gate));
        $this->assertFalse(ApprovalGateResource::canDeleteAny());
        $this->assertFalse(ApprovalGateResource::hasPage('create'));
        $this->assertFalse(ApprovalGateResource::hasPage('edit'));
        $this->assertSame(['index', 'view'], array_keys(ApprovalGateResource::getPages()));

        $this->get(ApprovalGateResource::getUrl('index'))->assertSuccessful();
        $this->get(ApprovalGateResource::getUrl('view', ['record' => $gate]))->assertSuccessful();

        Livewire::test(ListApprovalGates::class)
            ->assertCanSeeTableRecords([$gate])
            ->assertTableColumnExists('gate_type')
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('firstApprover.name')
            ->assertTableColumnExists('secondApprover.name');
    }

    public function test_navigation_badges_show_active_runs_and_pending_approvals(): void
    {
        $this->makeRun([
            'status' => AutomationRunStatus::Running,
            'started_at' => now(),
        ]);
        $this->makeGate();

        $this->assertSame('1', AutomationRunResource::getNavigationBadge());
        $this->assertSame('1', ApprovalGateResource::getNavigationBadge());
    }

    private function makeRun(array $overrides = []): AutomationRun
    {
        return AutomationRun::create([
            'pipeline' => 'test_pipeline',
            'status' => AutomationRunStatus::Pending,
            'dry_run' => true,
            'trigger' => AutomationTrigger::Scheduler,
            'budget_limit_micros' => 10_000,
            'meta' => ['source' => 'test'],
            ...$overrides,
        ]);
    }

    private function makeGate(): ApprovalGate
    {
        return ApprovalGate::create([
            'gateable_type' => (new News)->getMorphClass(),
            'gateable_id' => News::factory()->create()->getKey(),
            'gate_type' => ApprovalGateType::PublicPublish,
            'status' => ApprovalGateStatus::Pending,
            'expires_at' => now()->addDay(),
            'meta' => ['require_dual' => true],
        ]);
    }
}
