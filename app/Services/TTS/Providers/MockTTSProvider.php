<?php

namespace App\Services\TTS\Providers;

use App\Contracts\TTSProviderInterface;
use Illuminate\Support\Facades\File;

class MockTTSProvider implements TTSProviderInterface
{
    public function synthesize(string $text, array $options = []): array
    {
        $duration = max(1, (int) round(strlen($text) / 100));
        $path = storage_path('app/voice_mock_'.time().'.wav');
        File::ensureDirectoryExists(dirname($path));

        // Generate a valid 44-byte WAV with PCM silence so FFmpeg/media decoders never fail
        $sampleRate = 22050;
        $numSamples = $sampleRate * $duration;
        $dataSize = $numSamples * 2; // 16-bit mono
        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',
            36 + $dataSize,
            'WAVE',
            'fmt ',
            16,
            1, // PCM
            1, // mono
            $sampleRate,
            $sampleRate * 2,
            2,
            16,
            'data',
            $dataSize
        );
        file_put_contents($path, $header.str_repeat("\x00", $dataSize));

        return ['path' => $path, 'duration' => $duration, 'format' => 'wav'];
    }

    public function supportsLanguage(string $language): bool
    {
        return in_array($language, ['ar', 'en', 'fr']);
    }

    public function getProviderName(): string
    {
        return 'mock';
    }
}
