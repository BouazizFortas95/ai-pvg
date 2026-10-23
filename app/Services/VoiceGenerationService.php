<?php
namespace App\Services;
use App\Models\VideoScript;
use App\Models\VoiceGeneration;
use App\Services\TTS\TTSManager;
class VoiceGenerationService
{
    public function generate(VideoScript $script, string $language = 'en', ?string $voice = null): VoiceGeneration
    {
        $gen = VoiceGeneration::create([
            'video_script_id' => $script->getKey(),
            'provider' => (new TTSManager())->getProviderName(),
            'voice' => $voice ?? 'default',
            'language' => $language,
            'status' => 'generating',
            'started_at' => now(),
        ]);
        try {
            $tts = new TTSManager();
            if (! $tts->supportsLanguage($language)) {
                throw new \RuntimeException('Language not supported');
            }
            $narration = (string) ($script->narration ?? '');
            if (empty($narration)) {
                throw new \RuntimeException('Script narration is empty');
            }
            $result = $tts->synthesize($narration, ['language'=>$language,'voice'=>$voice]);
            $audioPath = $result['path'];
            $duration = (int) ($result['duration'] ?? 0);
            if (! file_exists($audioPath) || filesize($audioPath) < 100) {
                throw new \RuntimeException('Audio file missing or too small');
            }
            $finalPath = 'public/voice/'.(basename($audioPath));
            \\Illuminate\\Support\\Facades\\Storage::disk('public')->put('voice/'.basename($audioPath), file_get_contents($audioPath));
            $gen->update([
                'status'=>'completed',
                'audio_path'=>$finalPath,
                'duration'=>$duration,
                'completed_at'=>now(),
            ]);
        } catch (\Exception $e) {
            $gen->update(['status'=>'failed','error_message'=>substr($e->getMessage(),0,500),'completed_at'=>now()]);
        }
        return $gen->fresh();
    }
}
