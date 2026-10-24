<?php

namespace Tests\Feature;

use App\Models\FeatureFlag;
use App\Services\Automation\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeatureFlagServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_unknown_flags_default_to_disabled(): void
    {
        $service = app(FeatureFlagService::class);

        $this->assertFalse($service->enabled('unknown.flag'));
        $this->assertTrue($service->enabled('unknown.flag', true));
        $this->assertTrue($service->disabled('unknown.flag'));
    }

    public function test_flags_can_be_enabled_and_read_with_scope_and_metadata(): void
    {
        $service = app(FeatureFlagService::class);

        $flag = $service->set('automation.publishing', true, 'pipeline', ['owner' => 'ops']);

        $this->assertTrue($flag->enabled);
        $this->assertSame('pipeline', $flag->scope);
        $this->assertSame(['owner' => 'ops'], $flag->meta);
        $this->assertTrue($service->enabled('automation.publishing'));
        $this->assertSame(['automation.publishing' => true], $service->all());
        $this->assertTrue(FeatureFlag::isEnabled('automation.publishing'));

        $service->set('automation.publishing', false);

        $this->assertFalse($service->enabled('automation.publishing'));
    }
}
