<?php

namespace App\Services\Audio\ElevenLabs;

use Illuminate\Support\Facades\File;

class AudioCache
{
    protected string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? storage_path('app/audio_library/voices');
        File::ensureDirectoryExists($this->storageDir);
    }

    /**
     * Compute deterministic sha256 cache key.
     */
    public function computeKey(
        string $text,
        string $voiceId,
        string $modelId,
        array $settings = [],
        string $language = 'en',
    ): string {
        $normalizedText = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        ksort($settings);

        $payload = implode('|', [
            $normalizedText,
            $voiceId,
            $modelId,
            json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            strtolower($language),
        ]);

        return hash('sha256', $payload);
    }

    public function has(string $key): bool
    {
        return is_file($this->audioPath($key)) && is_file($this->metaPath($key));
    }

    /**
     * Retrieve cached audio and metadata.
     *
     * @return array{audio_path: string, metadata: array<string, mixed>}|null
     */
    public function get(string $key): ?array
    {
        if (! $this->has($key)) {
            return null;
        }

        $metaJson = file_get_contents($this->metaPath($key));
        $meta = json_decode((string) $metaJson, true) ?? [];

        return [
            'audio_path' => $this->audioPath($key),
            'metadata' => $meta,
        ];
    }

    /**
     * Store audio bytes and sidecar metadata into cache.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{audio_path: string, meta_path: string}
     */
    public function put(string $key, string $audioBinary, array $metadata = []): array
    {
        File::ensureDirectoryExists($this->storageDir);

        $audioPath = $this->audioPath($key);
        $metaPath = $this->metaPath($key);

        file_put_contents($audioPath, $audioBinary);

        $metadata['cached_at'] = now()->toIso8601String();
        $metadata['sha256'] = hash('sha256', $audioBinary);
        $metadata['file_size'] = strlen($audioBinary);

        file_put_contents($metaPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'audio_path' => $audioPath,
            'meta_path' => $metaPath,
        ];
    }

    public function audioPath(string $key): string
    {
        return $this->storageDir.'/'.$key.'.wav';
    }

    public function metaPath(string $key): string
    {
        return $this->storageDir.'/'.$key.'.json';
    }
}

