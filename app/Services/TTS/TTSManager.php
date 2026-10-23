<?php
namespace App\Services\TTS;
use App\Contracts\TTSProviderInterface;
use App\Services\TTS\Providers\MockTTSProvider;
class TTSManager
{
    protected TTSProviderInterface $provider;
    public function __construct(string $providerName = 'mock')
    {
        $this->provider = match ($providerName) {
            'mock' => new MockTTSProvider(),
            default => new MockTTSProvider(),
        };
    }
    public function provider(): TTSProviderInterface { return $this->provider; }
    public function synthesize(string $text, array $options = []): array { return $this->provider->synthesize($text, $options); }
    public function getProviderName(): string { return $this->provider->getProviderName(); }
    public function supportsLanguage(string $language): bool { return $this->provider->supportsLanguage($language); }
}
