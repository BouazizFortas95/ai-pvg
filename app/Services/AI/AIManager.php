<?php

namespace App\Services\AI;

use App\Contracts\AIProviderInterface;
use App\Services\AI\Providers\OllamaProvider;
use App\Services\AI\Providers\OpenAICompatibleProvider;

class AIManager
{
    protected AIProviderInterface $provider;

    public function __construct(string $providerName = 'ollama')
    {
        $this->provider = match ($providerName) {
            'openai' => new OpenAICompatibleProvider(
                config('services.ai.openai.endpoint', 'https://api.openai.com/v1'),
                config('services.ai.openai.api_key', env('AI_API_KEY'))
            ),
            'ollama' => new OllamaProvider(config('services.ai.ollama.base_url', 'http://localhost:11434')),
            default => new OllamaProvider,
        };
    }

    public function provider(): AIProviderInterface
    {
        return $this->provider;
    }

    public function generate(string $prompt, array $options = []): string
    {
        return $this->provider->generate($prompt, $options);
    }

    public function supportsStructuredOutput(): bool
    {
        return $this->provider->supportsStructuredOutput();
    }

    public function getProviderName(): string
    {
        return $this->provider->getProviderName();
    }
}
