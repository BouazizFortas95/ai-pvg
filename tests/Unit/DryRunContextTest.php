<?php

namespace Tests\Unit;

use App\Exceptions\Automation\DryRunViolationException;
use App\Models\AutomationRun;
use App\Services\Automation\DryRunContext;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DryRunContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_dry_run_forces_local_providers_and_blocks_external_actions(): void
    {
        config([
            'automation.dry_run' => true,
            'automation.providers.default' => 'openai',
            'automation.providers.fallback' => 'ollama',
            'automation.providers.tts' => 'mock',
        ]);

        $context = new DryRunContext;

        $this->assertTrue($context->isDryRun());
        $this->assertFalse($context->allowsPaidCalls());
        $this->assertSame('ollama', $context->providerFor('ai'));
        $this->assertSame('mock', $context->providerFor('tts'));
        $this->assertFalse($context->canPerformExternalAction());

        $this->expectException(DryRunViolationException::class);
        $this->expectExceptionMessage('dry-run is active');

        $context->assertActionAllowed('youtube.upload');
    }

    public function test_dry_run_output_is_always_labeled(): void
    {
        config(['automation.dry_run' => true]);

        $context = new DryRunContext;
        $output = $context->syntheticOutput('score', ['score' => 91]);
        $result = $context->syntheticStageResult('score', ['score' => 91]);

        $this->assertSame(91, $output['score']);
        $this->assertTrue($output['_automation']['dry_run']);
        $this->assertTrue($output['_automation']['labeled']);
        $this->assertSame('score', $output['_automation']['stage']);
        $this->assertTrue($result->successful());
        $this->assertTrue($result->output['_automation']['dry_run']);
    }

    public function test_live_mode_uses_configured_provider_but_run_level_dry_run_wins(): void
    {
        config([
            'automation.dry_run' => false,
            'automation.providers.default' => 'openai',
            'automation.providers.fallback' => 'ollama',
        ]);

        $context = new DryRunContext;
        $liveRun = new AutomationRun(['dry_run' => false]);
        $dryRun = new AutomationRun(['dry_run' => true]);

        $this->assertFalse($context->isDryRun($liveRun));
        $this->assertTrue($context->allowsPaidCalls($liveRun));
        $this->assertSame('openai', $context->providerFor('ai', $liveRun));
        $this->assertTrue($context->isDryRun($dryRun));
        $this->assertSame('ollama', $context->providerFor('ai', $dryRun));
    }
}
