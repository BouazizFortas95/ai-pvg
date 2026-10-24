<?php

namespace Tests\Feature;

use App\Exceptions\Automation\ScriptAgentsDisabledException;
use App\Models\FeatureFlag;
use App\Services\AI\ScriptPipeline\ScriptAgentsGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScriptAgentsGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'automation.script_agents.enabled' => false,
            'automation.script_agents.feature_flag' => 'script_agents.enabled',
        ]);
    }

    public function test_gate_requires_both_config_and_database_flag(): void
    {
        $gate = app(ScriptAgentsGate::class);

        $this->assertFalse($gate->enabled());

        FeatureFlag::setFlag('script_agents.enabled', true);
        $this->assertFalse($gate->enabled());

        config(['automation.script_agents.enabled' => true]);
        $this->assertTrue($gate->enabled());

        FeatureFlag::setFlag('script_agents.enabled', false);
        $this->assertFalse($gate->enabled());

        FeatureFlag::setFlag('script_agents.enabled', true);
        FeatureFlag::setFlag('unrelated.enabled', true);
        $this->assertTrue($gate->enabled());
    }

    public function test_assert_enabled_throws_when_disabled(): void
    {
        $this->expectException(ScriptAgentsDisabledException::class);

        app(ScriptAgentsGate::class)->assertEnabled();
    }

    public function test_gate_falls_back_to_config_when_feature_flag_table_is_missing(): void
    {
        Schema::dropIfExists('feature_flags');
        config(['automation.script_agents.enabled' => true]);

        $this->assertTrue(app(ScriptAgentsGate::class)->enabled());

        config(['automation.script_agents.enabled' => false]);

        $this->assertFalse(app(ScriptAgentsGate::class)->enabled());
    }
}
