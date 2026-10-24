<?php

namespace Tests\Feature;

use App\Models\ScriptFactCheck;
use App\Services\AI\ScriptPipeline\ScriptPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsScriptFixtures;
use Tests\TestCase;

class ScriptDiagnosticsTest extends TestCase
{
    use BuildsScriptFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_diagnostics_reports_g03_gate_tables_and_read_only_counts(): void
    {
        $this->enableScriptAgents();
        $result = app(ScriptPipelineService::class)->runPipeline($this->makeScriptCluster());
        $factCheckCount = ScriptFactCheck::count();

        $this->assertSame(3, count($result->variants));
        $this->assertGreaterThanOrEqual(3, $factCheckCount);

        $this->artisan('ai-pvg:diagnostics')
            ->expectsOutputToContain('Script agents enabled: YES (flag=script_agents.enabled; use_ai=NO)')
            ->expectsOutputToContain('Script agent tables: 2/2 OK')
            ->expectsOutputToContain(
                'Script agent data: script_variants=3; hooks=3; script_fact_checks='
                .$factCheckCount.'; pending_fact_check_gates=0',
            )
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_diagnostics_fails_closed_when_g03_tables_are_missing(): void
    {
        Schema::dropIfExists('script_fact_checks');
        Schema::dropIfExists('script_variants');

        $this->artisan('ai-pvg:diagnostics')
            ->expectsOutputToContain('Script agents enabled: NO (flag=script_agents.enabled; use_ai=NO)')
            ->expectsOutputToContain('Script agent tables: 0/2 OK; missing=script_variants,script_fact_checks')
            ->expectsOutputToContain('Script agent data: unavailable (tables missing)')
            ->assertSuccessful();
    }
}
