<?php

namespace App\Services\Audio\ElevenLabs;

use App\Exceptions\Audio\ElevenLabsAuthException;
use App\Exceptions\Audio\ElevenLabsException;
use App\Exceptions\Audio\ElevenLabsNetworkException;
use App\Exceptions\Audio\ElevenLabsQuotaExceededException;
use App\Exceptions\Audio\ElevenLabsValidationException;
use App\Services\Automation\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ElevenLabsClient
{
    public const PROVIDER_KEY = 'elevenlabs';

    protected string $baseUrl;

    protected ?string $apiKey;

    protected int $timeout;

    public function __construct(
        protected ?CircuitBreaker $breaker = null,
        ?string $apiKey = null,
        ?string $baseUrl = null,
        int $timeout = 30,
    ) {
        $this->breaker = $breaker ?? app(CircuitBreaker::class);
        $this->apiKey = $apiKey ?? config('automation.audio.elevenlabs.api_key', env('ELEVENLABS_API_KEY'));
        $this->baseUrl = rtrim($baseUrl ?? config('automation.audio.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');
        $this->timeout = $timeout;
    }

    /**
     * Get masked API key for safe display in diagnostics and UI.
     */
    public function maskedApiKey(): string
    {
        if (empty($this->apiKey)) {
            return 'NOT_CONFIGURED';
        }

        $length = strlen($this->apiKey);
        if ($length <= 8) {
            return '****';
        }

        return substr($this->apiKey, 0, 4).'...'.substr($this->apiKey, -4);
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Fetch available models from ElevenLabs.
     *
     * @return list<array<string, mixed>>
     */
    public function getModels(): array
    {
        $response = $this->request('GET', '/v1/models');

        return $response->json() ?? [];
    }

    /**
     * Fetch available voices from ElevenLabs.
     *
     * @return list<array<string, mixed>>
     */
    public function getVoices(): array
    {
        $response = $this->request('GET', '/v1/voices');

        return $response->json('voices') ?? [];
    }

    /**
     * Fetch current user subscription and character usage/limits.
     *
     * @return array<string, mixed>
     */
    public function getUserSubscription(): array
    {
        $response = $this->request('GET', '/v1/user/subscription');

        return $response->json() ?? [];
    }

    /**
     * Synthesize text to speech with timestamps.
     *
     * @param  array{
     *     model_id?: string,
     *     output_format?: string,
     *     voice_settings?: array<string, mixed>,
     *     previous_text?: string|null,
     *     next_text?: string|null
     * }  $options
     * @return array{
     *     audio_base64: string,
     *     alignment: array{characters: list<string>, character_start_times_seconds: list<float>, character_end_times_seconds: list<float>},
     *     normalized_alignment?: array{characters: list<string>, character_start_times_seconds: list<float>, character_end_times_seconds: list<float>}
     * }
     */
    public function textToSpeechWithTimestamps(string $voiceId, string $text, array $options = []): array
    {
        if (trim($text) === '') {
            throw new ElevenLabsValidationException('Text for speech synthesis cannot be empty.');
        }

        $format = $options['output_format'] ?? 'mp3_44100_128';
        $endpoint = "/v1/text-to-speech/{$voiceId}/with-timestamps?output_format={$format}";

        $payload = [
            'text' => $text,
            'model_id' => $options['model_id'] ?? 'eleven_multilingual_v2',
        ];

        if (! empty($options['voice_settings'])) {
            $payload['voice_settings'] = $options['voice_settings'];
        }

        if (! empty($options['previous_text'])) {
            $payload['previous_text'] = $options['previous_text'];
        }

        if (! empty($options['next_text'])) {
            $payload['next_text'] = $options['next_text'];
        }

        $response = $this->request('POST', $endpoint, $payload);

        $json = $response->json();
        if (! isset($json['audio_base64']) || ! isset($json['alignment'])) {
            throw new ElevenLabsException(
                'Invalid response from ElevenLabs with-timestamps endpoint: missing audio_base64 or alignment.',
                'Verify the voice ID and model support timestamps.'
            );
        }

        return $json;
    }

    /**
     * Generate sound effect audio.
     *
     * @param  array{
     *     output_format?: string,
     *     duration_seconds?: float|null,
     *     prompt_influence?: float|null,
     *     loop?: bool
     * }  $options
     * @return string Raw audio binary (e.g. mp3)
     */
    public function generateSoundEffect(string $text, array $options = []): string
    {
        if (trim($text) === '') {
            throw new ElevenLabsValidationException('Sound effect prompt cannot be empty.');
        }

        $format = $options['output_format'] ?? 'mp3_44100_128';
        $endpoint = "/v1/sound-generation?output_format={$format}";

        $payload = [
            'text' => $text,
        ];

        if (isset($options['duration_seconds']) && $options['duration_seconds'] !== null) {
            $payload['duration_seconds'] = (float) max(0.5, min(30.0, (float) $options['duration_seconds']));
        }

        if (isset($options['prompt_influence']) && $options['prompt_influence'] !== null) {
            $payload['prompt_influence'] = (float) max(0.0, min(1.0, (float) $options['prompt_influence']));
        }

        if (isset($options['loop'])) {
            $payload['loop'] = (bool) $options['loop'];
        }

        $response = $this->request('POST', $endpoint, $payload, 'audio/mpeg');

        return $response->body();
    }

    /**
     * Generate background music bed.
     *
     * @param  array{
     *     output_format?: string,
     *     duration_seconds?: float|null
     * }  $options
     * @return string Raw audio binary (e.g. mp3)
     */
    public function generateMusic(string $prompt, array $options = []): string
    {
        if (trim($prompt) === '') {
            throw new ElevenLabsValidationException('Music prompt cannot be empty.');
        }

        $format = $options['output_format'] ?? 'mp3_44100_128';
        $endpoint = "/v1/music?output_format={$format}";

        $payload = [
            'text' => $prompt,
        ];

        if (isset($options['duration_seconds']) && $options['duration_seconds'] !== null) {
            $payload['duration_seconds'] = (float) max(1.0, (float) $options['duration_seconds']);
        }

        $response = $this->request('POST', $endpoint, $payload, 'audio/mpeg');

        return $response->body();
    }

    /**
     * Execute an authenticated HTTP request with circuit breaker, retry backoff, and typed errors.
     */
    protected function request(string $method, string $path, array $data = [], string $accept = 'application/json'): Response
    {
        if (empty($this->apiKey)) {
            throw new ElevenLabsAuthException('ElevenLabs API key is missing. Set ELEVENLABS_API_KEY in .env.');
        }

        if ($this->breaker->isOpen(self::PROVIDER_KEY)) {
            throw new ElevenLabsQuotaExceededException(
                'ElevenLabs circuit breaker is OPEN due to consecutive recent failures or rate limits.'
            );
        }

        $url = $this->baseUrl.'/'.ltrim($path, '/');

        $headers = [
            'xi-api-key' => $this->apiKey,
            'Accept' => $accept,
        ];

        $pending = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->retry(3, function (int $attempt, Throwable $exception): int {
                // Exponential backoff with jitter on 429 or 5xx: 200ms, 400ms, 800ms + random
                $jitter = random_int(10, 80);

                return (100 * (2 ** $attempt)) + $jitter;
            }, function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if ($exception instanceof RequestException) {
                    $status = $exception->response->status();

                    return $status === 429 || $status >= 500;
                }

                return false;
            }, throw: false);

        try {
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url, $data),
                'POST' => $pending->post($url, $data),
                default => throw new ElevenLabsException("Unsupported HTTP method {$method}."),
            };
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure(self::PROVIDER_KEY, 'connection_timeout');
            throw new ElevenLabsNetworkException($e->getMessage());
        }

        if ($response->successful()) {
            $this->breaker->recordSuccess(self::PROVIDER_KEY);

            return $response;
        }

        $status = $response->status();
        $detail = $response->json('detail.message') ?? $response->json('detail') ?? $response->body();
        $safeMessage = is_array($detail) ? json_encode($detail) : (string) $detail;

        // Record failure against circuit breaker
        $this->breaker->recordFailure(self::PROVIDER_KEY, "http_{$status}");

        Log::warning('ElevenLabs API error', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'message' => $safeMessage,
        ]);

        if ($status === 401 || $status === 403) {
            throw new ElevenLabsAuthException("ElevenLabs authentication failed ({$status}): {$safeMessage}", $status);
        }

        if ($status === 429) {
            throw new ElevenLabsQuotaExceededException("ElevenLabs rate limit or quota exceeded: {$safeMessage}", $status);
        }

        if ($status === 422 || $status === 400) {
            throw new ElevenLabsValidationException("ElevenLabs request validation error: {$safeMessage}", null, $status);
        }

        throw new ElevenLabsException(
            "ElevenLabs API request failed with status {$status}: {$safeMessage}",
            'Check ElevenLabs service status or error details in logs.',
            $status,
        );
    }
}

