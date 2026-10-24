<?php

namespace Tests\Feature;

use App\Enums\CircuitState;
use App\Enums\ProviderHealthEventType;
use App\Models\ProviderHealthEvent;
use App\Services\Automation\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00'));
        config([
            'automation.circuit_breaker.failure_threshold' => 2,
            'automation.circuit_breaker.failure_window_seconds' => 300,
            'automation.circuit_breaker.cooldown_seconds' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_provider_breaker_opens_after_the_failure_threshold_and_sanitizes_messages(): void
    {
        $breaker = app(CircuitBreaker::class);

        $breaker->recordFailure('openai', ProviderHealthEventType::RateLimit, 429, 30, 'Authorization: Bearer sk-secret-token');
        $this->assertSame(CircuitState::Closed, $breaker->state('openai'));

        $breaker->recordFailure('openai', ProviderHealthEventType::Timeout, null, null, 'Request timed out');
        $this->assertSame(CircuitState::Open, $breaker->state('openai'));
        $this->assertTrue($breaker->isOpen('openai'));
        $this->assertSame(2, $breaker->failureCount('openai'));

        $event = ProviderHealthEvent::query()->oldest('id')->first();
        $this->assertNotNull($event);
        $this->assertStringContainsString('[REDACTED]', (string) $event->message_sanitized);
        $this->assertStringNotContainsString('sk-secret-token', (string) $event->message_sanitized);
    }

    public function test_provider_breaker_becomes_half_open_after_cooldown(): void
    {
        $breaker = app(CircuitBreaker::class);
        $breaker->open('elevenlabs', 'Rate limited');

        $this->assertSame(CircuitState::Open, $breaker->state('elevenlabs'));

        Carbon::setTestNow(now()->addSeconds(61));

        $this->assertSame(CircuitState::HalfOpen, $breaker->state('elevenlabs'));
        $this->assertFalse($breaker->isOpen('elevenlabs'));
        $this->assertTrue($breaker->canAttempt('elevenlabs'));
    }

    public function test_success_closes_the_provider_breaker_and_clears_failures(): void
    {
        $breaker = app(CircuitBreaker::class);
        $breaker->recordFailure('ollama', ProviderHealthEventType::Error, null, null, 'Temporary failure');
        $breaker->recordFailure('ollama', ProviderHealthEventType::Error, null, null, 'Temporary failure');

        $this->assertSame(CircuitState::Open, $breaker->state('ollama'));

        $breaker->recordSuccess('ollama');

        $this->assertSame(CircuitState::Closed, $breaker->state('ollama'));
        $this->assertSame(0, $breaker->failureCount('ollama'));
        $this->assertSame(2, ProviderHealthEvent::query()->where('provider', 'ollama')->count());
    }
}
