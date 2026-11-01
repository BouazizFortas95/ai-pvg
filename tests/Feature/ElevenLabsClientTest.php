<?php

namespace Tests\Feature;

use App\Exceptions\Audio\ElevenLabsAuthException;
use App\Exceptions\Audio\ElevenLabsNetworkException;
use App\Exceptions\Audio\ElevenLabsQuotaExceededException;
use App\Services\Audio\ElevenLabs\ElevenLabsClient;
use App\Services\Automation\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ElevenLabsClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
    }

    public function test_masked_api_key_never_exposes_secret(): void
    {
        $client = new ElevenLabsClient(apiKey: 'sk_test_1234567890abcdef');
        $masked = $client->maskedApiKey();

        $this->assertSame('sk_t...cdef', $masked);
        $this->assertStringNotContainsString('1234567890ab', $masked);

        $emptyClient = new ElevenLabsClient(apiKey: '');
        $this->assertSame('NOT_CONFIGURED', $emptyClient->maskedApiKey());
    }

    public function test_get_models_returns_model_list(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/models' => Http::response([
                [
                    'model_id' => 'eleven_multilingual_v2',
                    'name' => 'Eleven Multilingual v2',
                    'can_do_text_to_speech' => true,
                    'languages' => [['language_id' => 'en', 'name' => 'English']],
                ],
            ], 200),
        ]);

        $client = new ElevenLabsClient(apiKey: 'sk_mock_test_key');
        $models = $client->getModels();

        $this->assertCount(1, $models);
        $this->assertSame('eleven_multilingual_v2', $models[0]['model_id']);
        $this->assertTrue($models[0]['can_do_text_to_speech']);
    }

    public function test_text_to_speech_with_timestamps_returns_audio_and_alignment(): void
    {
        $fakeAudioBase64 = base64_encode('FAKE_AUDIO_DATA_BYTES_TEST_12345');
        Http::fake([
            'api.elevenlabs.io/v1/text-to-speech/21m00Tcm4TlvDq8ikWAM/with-timestamps*' => Http::response([
                'audio_base64' => $fakeAudioBase64,
                'alignment' => [
                    'characters' => ['H', 'e', 'l', 'l', 'o'],
                    'character_start_times_seconds' => [0.0, 0.05, 0.1, 0.15, 0.2],
                    'character_end_times_seconds' => [0.05, 0.1, 0.15, 0.2, 0.25],
                ],
            ], 200),
        ]);

        $client = new ElevenLabsClient(apiKey: 'sk_mock_test_key');
        $result = $client->textToSpeechWithTimestamps('21m00Tcm4TlvDq8ikWAM', 'Hello');

        $this->assertSame($fakeAudioBase64, $result['audio_base64']);
        $this->assertArrayHasKey('alignment', $result);
        $this->assertSame(['H', 'e', 'l', 'l', 'o'], $result['alignment']['characters']);
    }

    public function test_auth_failure_throws_typed_exception_without_leaking_key(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/models' => Http::response([
                'detail' => ['message' => 'Invalid API key'],
            ], 401),
        ]);

        $secretKey = 'super_secret_elevenlabs_token_xyz';
        $client = new ElevenLabsClient(apiKey: $secretKey);

        try {
            $client->getModels();
            $this->fail('Expected ElevenLabsAuthException was not thrown.');
        } catch (ElevenLabsAuthException $e) {
            $this->assertSame(401, $e->statusCode);
            $this->assertStringNotContainsString($secretKey, $e->getMessage());
            $this->assertNotNull($e->fixHint);
        }
    }

    public function test_rate_limit_429_throws_quota_exceeded_exception(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/models' => Http::response([
                'detail' => ['message' => 'Quota reached or rate limited'],
            ], 429),
        ]);

        $client = new ElevenLabsClient(apiKey: 'sk_mock_test_key');

        $this->expectException(ElevenLabsQuotaExceededException::class);
        $client->getModels();
    }

    public function test_circuit_breaker_trips_on_consecutive_failures(): void
    {
        Http::fake([
            'api.elevenlabs.io/v1/models' => Http::response([
                'detail' => 'Service Unavailable',
            ], 500),
        ]);

        $breaker = app(CircuitBreaker::class);
        $client = new ElevenLabsClient(breaker: $breaker, apiKey: 'sk_mock_test_key');

        for ($i = 0; $i < 5; $i++) {
            try {
                $client->getModels();
            } catch (\Throwable) {
                // Ignore expected failure
            }
        }

        $this->assertTrue($breaker->isOpen('elevenlabs'));

        // Subsequent call must fail immediately through circuit breaker without making an HTTP request
        $this->expectException(ElevenLabsQuotaExceededException::class);
        $client->getModels();
    }
}

