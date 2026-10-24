<?php

namespace Tests\Feature;

use App\Filament\Resources\ApprovalGates\ApprovalGateResource;
use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutomationResourceMissingTableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_automation_resources_fail_closed_when_tables_are_missing(): void
    {
        $this->assertFalse(Schema::hasTable('automation_runs'));
        $this->assertFalse(Schema::hasTable('approval_gates'));

        $this->assertFalse(AutomationRunResource::canAccess());
        $this->assertNull(AutomationRunResource::getNavigationBadge());
        $this->assertFalse(ApprovalGateResource::canAccess());
        $this->assertNull(ApprovalGateResource::getNavigationBadge());
    }
}
