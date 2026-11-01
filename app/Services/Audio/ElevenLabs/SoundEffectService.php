<?php

namespace App\Services\Audio\ElevenLabs;

use App\Enums\AiUsagePurpose;
use App\Exceptions\Rendering\AudioPipelineException;
use App\Models\AiUsageLedgerEntry;
use App\Services\Automation\BudgetGuard;
use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\Audio\SfxGenerator;
use App\Services\Rendering\ProcessRunner;
use App\Services\Automation\DryRunContext;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class SoundEffectService
{
    protected AudioCache $cache;

    public function __construct(
        protected ElevenLabsClient $client,
        protected BudgetGuard $budgetGuard,
        protected CostEstimator $costEstimator,
        protected AudioProbe $probe = new AudioProbe,
        protected ProcessRunner $runner = new ProcessRunner,
        protected SfxGenerator $localSfx = new SfxGenerator,
        ?AudioCache $cache = null,
    ) {
        $this->cache = $cache ?? new AudioCache(storage_path('app/audio_library/sfx'));
    }

    /**
     * Generate or retrieve a cached sound effect.
     *
     * @param  array{
     *     prompt_influence?: float|null,
     *     loop?: bool,
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
    public function generate(string $prompt, ?float $duration = null, array $options = []): array
    {
        $prompt = trim($prompt);
        if ($prompt === '') {
            $prompt = 'impact';
        }

        $loop = (bool) ($options['loop'] ?? false);
        $cacheKey = $this->computeKey($prompt, $duration, $loop);

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

        // 2. Decide whether to use ElevenLabs
        $isDryRun = (bool) ($options['dry_run'] ?? false) || app(DryRunContext::class)->isDryRun();

        $useElevenLabs = ! ($options['force_local'] ?? false)
            && ! $isDryRun
            && config('automation.audio.provider') === 'elevenlabs'
            && (bool) config('automation.audio.elevenlabs.sfx_enabled', false)
            && $this->client->isConfigured();

        if ($useElevenLabs) {
            try {
                return $this->generateViaElevenLabs($prompt, $duration, $cacheKey, $options);
            } catch (Throwable $e) {
                Log::warning('ElevenLabs SFX generation failed, falling back to local synthesis', [
                    'prompt' => $prompt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 3. Local fallback synthesis
        return $this->generateViaLocal($prompt, $duration, $cacheKey);
    }

    /**
     * Synthesize via ElevenLabs with budget guard and ledger recording.
     */
    protected function generateViaElevenLabs(string $prompt, ?float $duration, string $cacheKey, array $options): array
    {
        $estimatedMicros = $this->costEstimator->estimateSoundEffectMicros($prompt, $duration);
        $reservation = $this->budgetGuard->authorize($estimatedMicros, $options['run'] ?? null);

        $audioBinary = $this->client->generateSoundEffect($prompt, [
            'duration_seconds' => $duration,
            'prompt_influence' => $options['prompt_influence'] ?? null,
            'loop' => (bool) ($options['loop'] ?? false),
        ]);

        $wavBinary = $this->convertToWav48k($audioBinary);

        $meta = [
            'prompt' => $prompt,
            'model' => 'elevenlabs-sound-generation',
            'duration' => 0.0,
            'cost_micros' => $estimatedMicros,
            'provider' => 'elevenlabs',
        ];

        $cached = $this->cache->put($cacheKey, $wavBinary, $meta);
        $measuredDuration = round($this->probe->duration($cached['audio_path']), 3);

        // Update sidecar with measured duration
        $meta['duration'] = $measuredDuration;
        file_put_contents($cached['meta_path'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Settle spend in ledger
        $this->budgetGuard->settle($reservation, $estimatedMicros, [
            'provider' => 'elevenlabs',
            'model' => 'sound-generation',
            'purpose' => 'audio',
            'tokens_in' => mb_strlen($prompt, 'UTF-8'),
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
    protected function generateViaLocal(string $prompt, ?float $duration, string $cacheKey): array
    {
        $tmpOut = tempnam(sys_get_temp_dir(), 'sfx_local_').'.wav';
        $this->localSfx->generate($prompt, $tmpOut);

        $wavBinary = (string) file_get_contents($tmpOut);
        @unlink($tmpOut);

        $meta = [
            'prompt' => $prompt,
            'model' => 'ffmpeg-sfx-generator',
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
     * Compute cache key from prompt and parameters.
     */
    public function computeKey(string $prompt, ?float $duration = null, bool $loop = false): string
    {
        $normalized = trim(preg_replace('/\s+/', ' ', strtolower($prompt)) ?? $prompt);
        $durStr = $duration !== null ? (string) round($duration, 2) : 'auto';
        $loopStr = $loop ? 'loop' : 'once';

        return hash('sha256', implode('|', [$normalized, $durStr, $loopStr]));
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

        $tmpIn = tempnam(sys_get_temp_dir(), 'sfx_in_').'.mp3';
        $tmpOut = tempnam(sys_get_temp_dir(), 'sfx_out_').'.wav';
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
            throw new AudioPipelineException('Failed to convert SFX audio to 48kHz WAV: '.trim($result->errorOutput));
        }

        $wav = file_get_contents($tmpOut);
        @unlink($tmpOut);

        return (string) $wav;
    }
}
