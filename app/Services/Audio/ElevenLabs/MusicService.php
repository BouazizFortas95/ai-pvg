<?php

namespace App\Services\Audio\ElevenLabs;

use App\Enums\AiUsagePurpose;
use App\Exceptions\Rendering\AudioPipelineException;
use App\Models\AiUsageLedgerEntry;
use App\Services\Automation\BudgetGuard;
use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\Audio\MusicBedGenerator;
use App\Services\Rendering\ProcessRunner;
use App\Services\Automation\DryRunContext;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class MusicService
{
    protected AudioCache $cache;

    public function __construct(
        protected ElevenLabsClient $client,
        protected BudgetGuard $budgetGuard,
        protected CostEstimator $costEstimator,
        protected AudioProbe $probe = new AudioProbe,
        protected ProcessRunner $runner = new ProcessRunner,
        protected MusicBedGenerator $localMusic = new MusicBedGenerator,
        ?AudioCache $cache = null,
    ) {
        $this->cache = $cache ?? new AudioCache(storage_path('app/audio_library/music'));
    }

    /**
     * Generate or retrieve a cached music bed.
     *
     * @param  array{
     *     preset?: string,
     *     theme?: string,
     *     user_id?: int|null,
     *     force_local?: bool
     * }  $options
     * @return array{
     *     path: string,
     *     duration: float,
     *     cached: bool,
     *     hash: string,
     *     provider: string,
     *     cost_micros: int
     * }
     */
    public function generate(string $mood, float $targetDuration, array $options = []): array
    {
        $mood = trim($mood);
        if ($mood === '') {
            $mood = 'cyber';
        }

        $targetDuration = max(3.0, round($targetDuration, 2));
        $theme = (string) ($options['theme'] ?? $mood);
        $cacheKey = $this->computeKey($mood, $targetDuration, $theme);

        // 1. Cache hit check
        if ($this->cache->has($cacheKey)) {
            $cached = $this->cache->get($cacheKey);
            $meta = $cached['metadata'] ?? [];
            $dur = (float) ($meta['duration'] ?? 0);
            if ($dur <= 0.05 && is_file($cached['audio_path'])) {
                $dur = round($this->probe->duration($cached['audio_path']), 3);
            }

            return [
                'path' => $cached['audio_path'],
                'duration' => $dur,
                'cached' => true,
                'hash' => $cacheKey,
                'provider' => (string) ($meta['provider'] ?? 'elevenlabs'),
                'cost_micros' => 0,
            ];
        }

        // 2. Decide whether to use ElevenLabs Music
        $isDryRun = (bool) ($options['dry_run'] ?? false) || app(DryRunContext::class)->isDryRun();

        $useElevenLabs = ! ($options['force_local'] ?? false)
            && ! $isDryRun
            && config('automation.audio.provider') === 'elevenlabs'
            && (bool) config('automation.audio.elevenlabs.music_enabled', false)
            && (bool) config('automation.audio.elevenlabs.enabled', false)
            && $this->client->isConfigured();

        if ($useElevenLabs) {
            try {
                return $this->generateViaElevenLabs($mood, $targetDuration, $cacheKey, $options);
            } catch (Throwable $e) {
                Log::warning('ElevenLabs Music generation unavailable or failed, falling back to local synthesis', [
                    'mood' => $mood,
                    'duration' => $targetDuration,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. Local fallback synthesis
        return $this->generateViaLocal($mood, $targetDuration, $cacheKey, $options);
    }

    /**
     * Synthesize via ElevenLabs Music API.
     */
    protected function generateViaElevenLabs(string $mood, float $targetDuration, string $cacheKey, array $options): array
    {
        $estimatedMicros = $this->costEstimator->estimateMusicMicros($targetDuration);
        $reservation = $this->budgetGuard->authorize($estimatedMicros, $options['run'] ?? null);

        $audioBinary = $this->client->generateMusic($mood, [
            'duration_seconds' => $targetDuration,
        ]);

        $wavBinary = $this->convertToWav48k($audioBinary);

        $meta = [
            'mood' => $mood,
            'model' => 'elevenlabs-music',
            'duration' => 0.0,
            'cost_micros' => $estimatedMicros,
            'provider' => 'elevenlabs',
        ];

        $cached = $this->cache->put($cacheKey, $wavBinary, $meta);
        $measuredDuration = round($this->probe->duration($cached['audio_path']), 3);

        $meta['duration'] = $measuredDuration;
        file_put_contents($cached['meta_path'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Settle spend in ledger
        $this->budgetGuard->settle($reservation, $estimatedMicros, [
            'provider' => 'elevenlabs',
            'model' => 'music',
            'purpose' => 'audio',
            'tokens_in' => mb_strlen($mood, 'UTF-8'),
            'tokens_out' => (int) round($measuredDuration),
        ]);

        return [
            'path' => $cached['audio_path'],
            'duration' => $measuredDuration,
            'cached' => false,
            'hash' => $cacheKey,
            'provider' => 'elevenlabs',
            'cost_micros' => $estimatedMicros,
        ];
    }

    /**
     * Synthesize via deterministic local FFmpeg lavfi generator.
     */
    protected function generateViaLocal(string $mood, float $targetDuration, string $cacheKey, array $options): array
    {
        $tmpOut = tempnam(sys_get_temp_dir(), 'music_local_').'.wav';
        $this->localMusic->generate([
            'duration' => $targetDuration,
            'theme' => $options['theme'] ?? $mood,
            'preset' => $options['preset'] ?? '9:16',
        ], $tmpOut);

        $wavBinary = (string) file_get_contents($tmpOut);
        @unlink($tmpOut);

        $meta = [
            'mood' => $mood,
            'model' => 'ffmpeg-music-generator',
            'duration' => 0.0,
            'cost_micros' => 0,
            'provider' => 'local',
        ];

        $cached = $this->cache->put($cacheKey, $wavBinary, $meta);
        $measuredDuration = round($this->probe->duration($cached['audio_path']), 3);

        $meta['duration'] = $measuredDuration;
        file_put_contents($cached['meta_path'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [
            'path' => $cached['audio_path'],
            'duration' => $measuredDuration,
            'cached' => false,
            'hash' => $cacheKey,
            'provider' => 'local',
            'cost_micros' => 0,
        ];
    }

    /**
     * Compute cache key from mood, duration, and theme.
     */
    public function computeKey(string $mood, float $duration, string $theme = 'cyber'): string
    {
        $normalizedMood = trim(preg_replace('/\s+/', ' ', strtolower($mood)) ?? $mood);
        $durStr = (string) round($duration, 1);
        $themeStr = trim(strtolower($theme));

        return hash('sha256', implode('|', [$normalizedMood, $durStr, $themeStr]));
    }

    /**
     * Convert audio stream to 48kHz stereo WAV.
     */
    protected function convertToWav48k(string $audioBinary): string
    {
        if (app()->environment('testing') && str_starts_with($audioBinary, 'RIFF')) {
            return $audioBinary;
        }

        if (app()->environment('testing') && ! HyperFramesRuntime::ffmpegPath()) {
            return $audioBinary;
        }

        $ffmpeg = HyperFramesRuntime::ffmpegPath();
        if ($ffmpeg === null) {
            return $audioBinary;
        }

        $tmpIn = tempnam(sys_get_temp_dir(), 'music_in_').'.mp3';
        $tmpOut = tempnam(sys_get_temp_dir(), 'music_out_').'.wav';
        file_put_contents($tmpIn, $audioBinary);

        $result = $this->runner->run([
            $ffmpeg, '-y', '-hide_banner', '-nostats',
            '-i', $tmpIn,
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $tmpOut,
        ], null, HyperFramesRuntime::environment(), 60);

        @unlink($tmpIn);

        if (! $result->successful || ! is_file($tmpOut)) {
            @unlink($tmpOut);
            throw new AudioPipelineException('Failed to convert Music audio to 48kHz WAV: '.trim($result->errorOutput));
        }

        $wav = file_get_contents($tmpOut);
        @unlink($tmpOut);

        return (string) $wav;
    }
}
