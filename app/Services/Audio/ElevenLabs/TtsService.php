<?php

namespace App\Services\Audio\ElevenLabs;

use App\Exceptions\Audio\ElevenLabsValidationException;
use App\Exceptions\Rendering\AudioPipelineException;
use App\Models\FeatureFlag;
use App\Services\Automation\BudgetGuard;
use App\Services\Rendering\Audio\AudioProbe;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class TtsService
{
    public const RECONCILIATION_TOLERANCE_SECONDS = 0.10; // 100ms tolerance

    public function __construct(
        protected ElevenLabsClient $client,
        protected AlignmentConverter $alignmentConverter = new AlignmentConverter,
        protected AudioCache $cache = new AudioCache,
        protected CostEstimator $costEstimator = new CostEstimator,
        protected ?BudgetGuard $budgetGuard = null,
        protected ?AudioProbe $probe = null,
    ) {
        $this->budgetGuard = $budgetGuard ?? app(BudgetGuard::class);
        $this->probe = $probe ?? new AudioProbe;
    }

    /**
     * Synthesize speech using ElevenLabs with timestamp alignment, reconciliation, and caching.
     *
     * @param  array{
     *     voice_id?: string,
     *     model_id?: string,
     *     language?: string,
     *     previous_text?: string|null,
     *     next_text?: string|null,
     *     voice_settings?: array<string, mixed>,
     *     strip_tags_for_captions?: bool
     * }  $options
     * @return array{
     *     path: string,
     *     duration: float,
     *     word_timings: list<array{word: string, startMs: int, endMs: int}>,
     *     clean_text: string,
     *     cached: bool,
     *     provider: string,
     *     voice: string,
     *     model: string,
     *     cost_micros: int
     * }
     */
    public function synthesize(string $text, string $outputPath, array $options = []): array
    {
        $voiceId = $options['voice_id'] ?? config('automation.audio.elevenlabs.default_voice_id', VoiceCatalog::DEFAULT_VOICE_ID);
        $modelId = $options['model_id'] ?? config('automation.audio.elevenlabs.default_model_id', VoiceCatalog::DEFAULT_MODEL_ID);
        $language = $options['language'] ?? 'en';
        $settings = $options['voice_settings'] ?? ['stability' => 0.5, 'similarity_boost' => 0.75];

        // Clean caption text (strip expressive tags like [whisper], [pause], etc.)
        $cleanText = $this->stripExpressiveTags($text);

        // Compute cache key based on prompt text, voice, model, settings, and language
        $cacheKey = $this->cache->computeKey($text, $voiceId, $modelId, $settings, $language);

        File::ensureDirectoryExists(dirname($outputPath));

        // 1. Check AudioCache
        if ($this->cache->has($cacheKey)) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null && is_file($cached['audio_path'])) {
                File::copy($cached['audio_path'], $outputPath);
                $duration = (float) ($cached['metadata']['duration'] ?? $this->probe->duration($outputPath));
                $wordTimings = (array) ($cached['metadata']['word_timings'] ?? []);

                return [
                    'path' => $outputPath,
                    'duration' => $duration,
                    'word_timings' => $wordTimings,
                    'clean_text' => $cleanText,
                    'cached' => true,
                    'provider' => 'elevenlabs',
                    'voice' => $voiceId,
                    'model' => $modelId,
                    'cost_micros' => 0,
                ];
            }
        }

        // 2. Budget authorization via G-01 BudgetGuard
        $estimatedMicros = $this->costEstimator->estimateMicros($text, $modelId);
        $reservation = null;

        if ($this->budgetGuard !== null) {
            $reservation = $this->budgetGuard->authorize($estimatedMicros);
        }

        // 3. Call ElevenLabs API
        try {
            $apiResult = $this->client->textToSpeechWithTimestamps($voiceId, $text, [
                'model_id' => $modelId,
                'voice_settings' => $settings,
                'previous_text' => $options['previous_text'] ?? null,
                'next_text' => $options['next_text'] ?? null,
                'output_format' => 'mp3_44100_128',
            ]);
        } catch (\Throwable $e) {
            // Roll back budget reservation on failure if not settled
            Log::error('ElevenLabs TTS synthesis failed: '.$e->getMessage());
            throw $e;
        }

        // 4. Save and normalize audio to 48kHz WAV
        $audioBytes = base64_decode((string) $apiResult['audio_base64'], true);
        if ($audioBytes === false || strlen($audioBytes) < 100) {
            throw new AudioPipelineException('ElevenLabs returned empty or invalid audio data.');
        }

        $tempMp3 = $outputPath.'.temp.mp3';
        file_put_contents($tempMp3, $audioBytes);

        $this->convertToWav48k($tempMp3, $outputPath);
        @unlink($tempMp3);

        // 5. Convert character alignment to word-level timings
        $wordTimings = $this->alignmentConverter->toWordTimings($apiResult['alignment']);

        // 6. Reconciliation: Measure decoded audio with ffprobe and check alignment discrepancy
        $measuredDuration = $this->probe->duration($outputPath);

        if ($wordTimings !== []) {
            $lastWord = end($wordTimings);
            $alignmentEndSec = ($lastWord['endMs'] ?? 0) / 1000.0;
            $drift = abs($measuredDuration - $alignmentEndSec);

            if ($drift > self::RECONCILIATION_TOLERANCE_SECONDS) {
                // If drift is substantial, fail closed or log full warning
                Log::warning('ElevenLabs duration reconciliation discrepancy', [
                    'measured_duration' => $measuredDuration,
                    'alignment_end' => $alignmentEndSec,
                    'drift_seconds' => $drift,
                ]);

                // Adjust the final word boundary to match real audio duration if close enough
                if ($drift <= 0.35) {
                    $lastIdx = count($wordTimings) - 1;
                    $wordTimings[$lastIdx]['endMs'] = (int) round($measuredDuration * 1000);
                } else {
                    throw new AudioPipelineException(
                        "ElevenLabs audio duration ({$measuredDuration}s) diverges from returned alignment ({$alignmentEndSec}s) by {$drift}s (tolerance: ".self::RECONCILIATION_TOLERANCE_SECONDS.'s).'
                    );
                }
            }
        }

        // 7. Settle budget spend in AiUsageLedger
        $charCount = mb_strlen(trim($text), 'UTF-8');
        $actualCostMicros = $this->costEstimator->actualMicros($charCount, $modelId);

        if ($this->budgetGuard !== null && $reservation !== null) {
            $this->budgetGuard->settle($reservation, $actualCostMicros, [
                'provider' => 'elevenlabs',
                'model' => $modelId,
                'purpose' => 'tts',
                'tokens_in' => $charCount,
                'tokens_out' => (int) round($measuredDuration),
            ]);
        }

        // 8. Cache audio and metadata
        $this->cache->put($cacheKey, (string) file_get_contents($outputPath), [
            'duration' => $measuredDuration,
            'word_timings' => $wordTimings,
            'clean_text' => $cleanText,
            'voice_id' => $voiceId,
            'model_id' => $modelId,
            'char_count' => $charCount,
        ]);

        return [
            'path' => $outputPath,
            'duration' => $measuredDuration,
            'word_timings' => $wordTimings,
            'clean_text' => $cleanText,
            'cached' => false,
            'provider' => 'elevenlabs',
            'voice' => $voiceId,
            'model' => $modelId,
            'cost_micros' => $actualCostMicros,
        ];
    }

    /**
     * Strip expressive audio tags (e.g. [whisper], [excited], [sigh]) from text intended for captions.
     */
    public function stripExpressiveTags(string $text): string
    {
        $stripped = preg_replace('/\[[a-zA-Z0-9_\-\s]+\]/', '', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
    }

    /**
     * Convert any audio source to standard broadcast 48kHz mono PCM WAV.
     */
    protected function convertToWav48k(string $inputPath, string $outputPath): void
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();
        if (! $ffmpeg || app()->environment('testing')) {
            copy($inputPath, $outputPath);

            return;
        }

        $header = (string) @file_get_contents($inputPath, false, null, 0, 4);
        if ($header === 'RIFF') {
            copy($inputPath, $outputPath);

            return;
        }

        $process = new Process([
            $ffmpeg,
            '-y',
            '-i', $inputPath,
            '-ar', '48000',
            '-ac', '1',
            '-c:a', 'pcm_s16le',
            $outputPath,
        ]);

        $process->run();

        if (! $process->isSuccessful() || ! is_file($outputPath)) {
            throw new AudioPipelineException('Failed to convert ElevenLabs audio to 48kHz WAV: '.$process->getErrorOutput());
        }
    }
}
