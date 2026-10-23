<?php
namespace App\Services\TTS\Providers;
use App\Contracts\TTSProviderInterface;
class MockTTSProvider implements TTSProviderInterface
{
    public function synthesize(string $text, array $options = []): array
    {
        $path = storage_path('app/voice_mock_'.time().'.mp3');
        $bytes = 'FAKE_AUDIO_' . strlen($text);
        $audio = b"\xff\xfb" . str_repeat("\x00", 256) . $bytes;
        file_put_contents($path, $audio);
        return ['path' => $path, 'duration' => max(1, strlen($text) / 100), 'format' => 'mp3'];
    }
    public function supportsLanguage(string $language): bool { return in_array($language, ['ar','en','fr']); }
    public function getProviderName(): string { return 'mock'; }
}
