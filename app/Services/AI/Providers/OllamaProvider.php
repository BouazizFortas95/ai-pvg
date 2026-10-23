<?php

namespace App\Services\AI\Providers;

use App\Contracts\AIProviderInterface;
use Illuminate\Support\Facades\Http;

class OllamaProvider implements AIProviderInterface
{
    public function __construct(private string $baseUrl = 'http://localhost:11434') {}

    public function generate(string $prompt, array $options = []): string
    {
        $model = $options['model'] ?? env('OLLAMA_MODEL', 'llama3');
        $response = Http::timeout(60)->post($this->baseUrl.'/api/generate', [
            'model' => $model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => $options['temperature'] ?? 0.7,
                'num_predict' => $options['max_tokens'] ?? 2000,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Ollama request failed: '.$response->status());
        }

        return $response->json('response') ?? '';
    }

    public function supportsStructuredOutput(): bool
    {
        return false;
    }

    public function getProviderName(): string
    {
        return 'ollama';
    }
}
