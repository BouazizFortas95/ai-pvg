<?php

namespace App\Services\Audio\ElevenLabs;

use App\Models\VoiceProfile;
use Illuminate\Support\Facades\Cache;

class VoiceCatalog
{
    public const DEFAULT_VOICE_ID = '21m00Tcm4TlvDq8ikWAM'; // Rachel

    public const DEFAULT_MODEL_ID = 'eleven_multilingual_v2';

    public function __construct(
        protected ElevenLabsClient $client,
    ) {}

    /**
     * Get TTS-capable models supporting the requested language.
     *
     * @return list<array{model_id: string, name: string, languages: list<string>}>
     */
    public function getTtsModels(string $language = 'en'): array
    {
        $cacheKey = 'elevenlabs:models:'.$language;

        return Cache::remember($cacheKey, 3600, function () use ($language): array {
            if (! $this->client->isConfigured()) {
                return $this->fallbackModels();
            }

            try {
                $raw = $this->client->getModels();
            } catch (\Throwable) {
                return $this->fallbackModels();
            }

            $models = [];
            foreach ($raw as $m) {
                if (empty($m['can_do_text_to_speech'])) {
                    continue;
                }

                $supportedLangs = array_column($m['languages'] ?? [], 'language_id');
                // If model has specific languages listed, confirm support
                if (! empty($supportedLangs) && ! in_array(strtolower($language), array_map('strtolower', $supportedLangs), true)) {
                    continue;
                }

                $models[] = [
                    'model_id' => (string) $m['model_id'],
                    'name' => (string) ($m['name'] ?? $m['model_id']),
                    'languages' => $supportedLangs,
                ];
            }

            return $models !== [] ? $models : $this->fallbackModels();
        });
    }

    /**
     * Get available voices.
     *
     * @return list<array{voice_id: string, name: string, category: string, labels: array<string, string>}>
     */
    public function getVoices(): array
    {
        return Cache::remember('elevenlabs:voices:all', 3600, function (): array {
            if (! $this->client->isConfigured()) {
                return $this->fallbackVoices();
            }

            try {
                $raw = $this->client->getVoices();
            } catch (\Throwable) {
                return $this->fallbackVoices();
            }

            $voices = [];
            foreach ($raw as $v) {
                $voices[] = [
                    'voice_id' => (string) $v['voice_id'],
                    'name' => (string) ($v['name'] ?? 'Unknown Voice'),
                    'category' => (string) ($v['category'] ?? 'premade'),
                    'labels' => (array) ($v['labels'] ?? []),
                ];
            }

            return $voices !== [] ? $voices : $this->fallbackVoices();
        });
    }

    /**
     * Sync default stock voice profiles into the database.
     */
    public function syncVoiceProfiles(): int
    {
        $voices = $this->getVoices();
        $count = 0;

        foreach ($voices as $v) {
            VoiceProfile::query()->updateOrCreate(
                ['provider' => 'elevenlabs', 'voice_id' => $v['voice_id']],
                [
                    'name' => $v['name'],
                    'model_id' => self::DEFAULT_MODEL_ID,
                    'language' => 'en',
                    'settings' => ['stability' => 0.5, 'similarity_boost' => 0.75],
                    'license_note' => 'ElevenLabs Premade / Stock Voice License',
                    'is_active' => true,
                ]
            );
            $count++;
        }

        return $count;
    }

    protected function fallbackModels(): array
    {
        return [
            ['model_id' => 'eleven_multilingual_v2', 'name' => 'Eleven Multilingual v2', 'languages' => ['en', 'es', 'fr', 'de', 'it', 'pt', 'pl', 'ar']],
            ['model_id' => 'eleven_flash_v2_5', 'name' => 'Eleven Flash v2.5 (Fast / Low Latency)', 'languages' => ['en']],
            ['model_id' => 'eleven_monolingual_v1', 'name' => 'Eleven English v1', 'languages' => ['en']],
        ];
    }

    protected function fallbackVoices(): array
    {
        return [
            ['voice_id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel (Calm / Narration)', 'category' => 'premade', 'labels' => ['accent' => 'american', 'gender' => 'female']],
            ['voice_id' => 'AZnzlk1XvdvUeBnXmlld', 'name' => 'Domi (Strong / Confident)', 'category' => 'premade', 'labels' => ['accent' => 'american', 'gender' => 'female']],
            ['voice_id' => 'EXAVITQu4vr4xnSDxMaL', 'name' => 'Bella (Expressive / Warm)', 'category' => 'premade', 'labels' => ['accent' => 'american', 'gender' => 'female']],
            ['voice_id' => 'ErXwobaYiN019PkySvjV', 'name' => 'Antoni (Well-rounded / Tech)', 'category' => 'premade', 'labels' => ['accent' => 'american', 'gender' => 'male']],
            ['voice_id' => 'VR6AewLTigWG4xSOukaG', 'name' => 'Arnold (Crisp / News)', 'category' => 'premade', 'labels' => ['accent' => 'american', 'gender' => 'male']],
        ];
    }
}

