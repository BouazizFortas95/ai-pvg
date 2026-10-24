<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AutomationTickCommandSafetyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_disabled_tick_returns_success_without_touching_control_plane_tables(): void
    {
        config(['automation.enabled' => false]);

        $this->assertFalse(Schema::hasTable('budget_periods'));
        $this->assertFalse(Schema::hasTable('approval_gates'));

        $this->artisan('ai-pvg:automation:tick')
            ->expectsOutputToContain('Automation disabled; tick is a no-op.')
            ->assertSuccessful();
    }

    public function test_enabled_tick_fails_gracefully_when_tables_are_missing(): void
    {
        config(['automation.enabled' => true]);

        $this->assertFalse(Schema::hasTable('budget_periods'));

        $this->artisan('ai-pvg:automation:tick')
            ->expectsOutputToContain('control-plane tables are missing')
            ->assertFailed();
    }
}
