<?php

namespace Tests\Unit;

use App\Services\Automation\PipelineRegistry;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class PipelineRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_configured_and_code_registered_pipelines_are_available(): void
    {
        config(['automation.pipelines' => ['demo' => ['score', 'script']]]);

        $registry = new PipelineRegistry;

        $this->assertTrue($registry->has('demo'));
        $this->assertSame(['score', 'script'], $registry->stages('demo'));
        $this->assertSame(['demo' => ['score', 'script']], $registry->pipelines());

        $registry->register('media', ['voice', 'render']);

        $this->assertTrue($registry->has('media'));
        $this->assertSame(['voice', 'render'], $registry->stages('media'));
        $this->assertSame([], $registry->stages('missing'));
    }

    public function test_invalid_stage_definitions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PipelineRegistry)->register('bad', ['']);
    }
}
