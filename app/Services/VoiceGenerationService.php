<?php

namespace App\Services;

use App\Models\VideoScript;
use App\Models\VoiceGeneration;
use App\Services\Rendering\Audio\TtsGenerator;
use App\Services\TTS\TTSManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class VoiceGenerationService
{
    public function generate(VideoScript $script, string $language = 'en', ?string $voice = null): VoiceGeneration
    {
        $gen = VoiceGeneration::create([
            'video_script_id' => $script->getKey(),
            'provider' => 'pending',
            'voice' => $voice ?? 'default',
            'language' => $language,
            'status' => 'generating',
            'started_at' => now(),
        ]);

        try {
            $narration = (string) ($script->narration ?? '');
            if (empty($narration)) {
                throw new \RuntimeException('Script narration is empty');
            }

            $audioPath = null;
            $duration = 0;
            $providerName = 'local';

            // Try local Windows SAPI / Kokoro speech synthesis first (free, offline, real audible narration)
            if (class_exists(TtsGenerator::class) && ! app()->environment('testing')) {
                try {
                    /** @var TtsGenerator $ttsGen */
                    $ttsGen = app(TtsGenerator::class);
                    $destWav = storage_path('app/public/voice/voice_'.$script->getKey().'_'.time().'.wav');
                    File::ensureDirectoryExists(dirname($destWav));
                    $synth = $ttsGen->synthesize($narration, $language, $voice, $destWav);
                    if (is_file($synth['path']) && filesize($synth['path']) > 100) {
                        $audioPath = $synth['path'];
                        $duration = (int) round($synth['duration'] ?? 0);
                        $providerName = $synth['provider'] ?? 'sapi';
                        $voice = $synth['voice'] ?? ($voice ?? 'default');
                    }
                } catch (\Throwable) {
                    // Fall back to TTSManager
                }
            }

            // Fallback to TTSManager
            if ($audioPath === null) {
                $tts = new TTSManager;
                if (! $tts->supportsLanguage($language)) {
                    throw new \RuntimeException('Language not supported');
                }
                $result = $tts->synthesize($narration, ['language' => $language, 'voice' => $voice]);
                $audioPath = $result['path'];
                $duration = (int) ($result['duration'] ?? 0);
                $providerName = $tts->getProviderName();
            }

            if (! file_exists($audioPath) || filesize($audioPath) < 100) {
                throw new \RuntimeException('Audio file missing or too small');
            }

            $filename = basename($audioPath);
            $finalPath = 'public/voice/'.$filename;
            if (! is_file(storage_path('app/'.$finalPath))) {
                Storage::disk('public')->put('voice/'.$filename, file_get_contents($audioPath));
            }

            $gen->update([
                'status' => 'completed',
                'provider' => $providerName,
                'voice' => $voice ?? 'default',
                'audio_path' => $finalPath,
                'duration' => $duration,
                'completed_at' => now(),
            ]);
        } catch (\Exception $e) {
            $gen->update([
                'status' => 'failed',
                'error_message' => substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);
        }

        return $gen->fresh();
    }
}
