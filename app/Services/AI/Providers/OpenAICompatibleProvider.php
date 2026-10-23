<?php

namespace App\Services\AI\Providers;

use App\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;

class OpenAICompatibleProvider implements AIProviderInterface
{
    public function __construct(
        private string $baseUrl = 'https://api.openai.com/v1',
        private string $apiKey = ''
    ) {}

    public function generate(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? 'gpt-4';
        $key = $this->apiKey ?: env('AI_API_KEY', '');
        $response = Http::withToken($key)->timeout(60)->post($this->baseUrl.'/chat/completions', [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $options['system_prompt'] ?? 'You are a video script assistant.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => $options['temperature'] ?? 0.7,
            'max_tokens' => $options['max_tokens'] ?? 2000,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenAI-compatible request failed: '.$response->status().' '.($response->json('error.message') ?? ''));
        }

        return $response->json('choices.0.message.content') ?? '';
    }

    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return 'openai';
    }
}
