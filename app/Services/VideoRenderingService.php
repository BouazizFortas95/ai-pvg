<?php

namespace App\Services;

use App\Enums\VideoScriptStatus;
use App\Models\AudioAsset;
use App\Models\CaptionTrack;
use App\Models\News;
use App\Models\SceneAsset;
use App\Models\VideoRender;
use App\Services\AI\Captions\AudioMixService;
use App\Services\Media\MediaProbeService;
use App\Support\RemotionRuntime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class VideoRenderingService
{
    protected MediaProbeService $probe;

    public function __construct(?MediaProbeService $probe = null)
    {
        $this->probe = $probe ?? app(MediaProbeService::class);
    }

    public function render(VideoRender $render): VideoRender
    {
        $script = $render->videoScript;

        $scriptStatus = $script?->status instanceof \BackedEnum
            ? $script->status->value
            : $script?->status;

        if (! $script || $scriptStatus !== VideoScriptStatus::Approved->value) {
            $render->update(['status' => 'failed', 'error_message' => 'Script not approved']);

            return $render->fresh();
        }

        $scenes = $script->scenes ?? [];
        if (empty($scenes)) {
            $render->update(['status' => 'failed', 'error_message' => 'No scenes']);

            return $render->fresh();
        }

        $enrichedScenes = $this->enrichScenes($script, $scenes);

        $narration = implode(' ', array_map(
            fn (array $scene): string => (string) ($scene['narration'] ?? $scene['headline'] ?? ''),
            $enrichedScenes,
        ));

        $audioUrl = null;
        $audioAsset = null;

        if (! empty($narration)) {
            $audioAsset = $this->generateNarrationAudio($script, $narration);
        }

        $backgroundAsset = null;
        if ($audioAsset !== null) {
            $backgroundAsset = $this->generateBackgroundAudio($script, $audioAsset->duration_seconds ?? 0);
        }

        $captionTrack = null;
        $track = CaptionTrack::where('video_script_id', $script->getKey())
            ->orderBy('verified_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();
        if ($track) {
            $captionTrack = [
                'language' => $track->language,
                'source' => $track->source instanceof \BackedEnum ? $track->source->value : $track->source,
                'status' => $track->status,
                'chunks' => $track->chunks ?? [],
                'wordTimings' => $track->word_timings ?? [],
            ];
        }

        $visualAssets = SceneAsset::where('video_script_id', $script->getKey())
            ->with('visualAsset')
            ->get()
            ->filter(fn ($sa) => $sa->visualAsset?->isVerified() === true)
            ->map(fn ($sa) => [
                'path' => $sa->visualAsset->storage_path,
                'attribution' => $sa->visualAsset->attribution_text ?? $sa->attribution_text,
                'status' => $sa->visualAsset->status,
            ])
            ->values()
            ->all();

        $payload = [
            'scenes' => $enrichedScenes,
            'title' => $script->title,
            'language' => $script->language,
            'target_duration' => $script->target_duration,
            'template' => $render->template,
        ];

        if ($captionTrack) {
            $payload['captionTrack'] = $captionTrack;
        }
        if (! empty($visualAssets)) {
            $payload['visualAssets'] = $visualAssets;
        }

        $jsonFile = storage_path('app/tmp/remotion_payload_'.$render->getKey().'.json');
        $configuredOutputDir = (string) config('remotion.output_dir', 'public/videos');
        $outputDir = RemotionRuntime::isAbsolute($configuredOutputDir)
            ? $configuredOutputDir
            : base_path($configuredOutputDir);
        $outputFile = rtrim($outputDir, '/\\').DIRECTORY_SEPARATOR.'render_'.$render->getKey().'.mp4';
        $relativeOutput = RemotionRuntime::isAbsolute($configuredOutputDir)
            ? $outputFile
            : rtrim($configuredOutputDir, '/\\').'/render_'.$render->getKey().'.mp4';

        File::ensureDirectoryExists(dirname($jsonFile));
        File::ensureDirectoryExists(dirname($outputFile));

        file_put_contents($jsonFile, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $cliPath = RemotionRuntime::cliPath();
        if (! is_file($cliPath)) {
            @unlink($jsonFile);
            $render->update([
                'status' => 'failed',
                'error_message' => 'Remotion CLI missing: '.$cliPath,
                'completed_at' => now(),
            ]);

            return $render->fresh();
        }

        $render->update(['status' => 'rendering', 'started_at' => now(), 'completed_at' => null]);

        $process = new Process([
            RemotionRuntime::nodeBinary(),
            $cliPath,
            '--input', $jsonFile,
            '--output', $outputFile,
        ], RemotionRuntime::directory(), RemotionRuntime::environment(), null, (int) config('remotion.timeout', 600));

        try {
            $process->run();
        } catch (\Throwable $exception) {
            Log::error('Remotion process failed to start', [
                'render_id' => $render->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }

        if (! $process->isSuccessful()) {
            $error = $this->tail($process->getErrorOutput() ?: $process->getOutput());
            Log::error('Remotion render failed', [
                'render_id' => $render->getKey(),
                'exit_code' => $process->getExitCode(),
                'error' => $error,
            ]);
            @unlink($jsonFile);
            $render->update([
                'status' => 'failed',
                'error_message' => 'Remotion exit '.$process->getExitCode().': '.$error,
                'completed_at' => now(),
            ]);

            return $render->fresh();
        }

        $tempOutput = $outputFile.'.temp.mp4';

        $size = is_file($outputFile) ? (filesize($outputFile) ?: 0) : 0;
        if ($size < 100) {
            @unlink($jsonFile);
            $render->update([
                'status' => 'failed',
                'error_message' => 'Render output missing or too small.',
                'completed_at' => now(),
            ]);

            return $render->fresh();
        }

        // Mux audio into the rendered video if available.
        $audioInputs = [];
        if ($audioAsset !== null) {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $audioAsset->storage_path);
            if (is_file($path)) {
                $audioInputs[] = $path;
            }
        }
        if ($backgroundAsset !== null) {
            $path = str_replace('/', DIRECTORY_SEPARATOR, $backgroundAsset->storage_path);
            if (is_file($path)) {
                $audioInputs[] = $path;
            }
        }

        if ($audioInputs !== []) {
            $ffmpeg = RemotionRuntime::ffmpegPath();
            if ($ffmpeg) {
                $inputArgs = [];
                $filterInputs = [];
                $mapArgs = ['-map', '0:v'];
                $inputIndex = 1;
                foreach ($audioInputs as $audioPath) {
                    $inputArgs[] = '-i';
                    $inputArgs[] = escapeshellarg($audioPath);
                    $filterInputs[] = '['.$inputIndex.':a]';
                    $inputIndex++;
                }
                $filterInputsStr = implode('', $filterInputs);
                $filterComplex = $filterInputsStr.'amix=inputs='.count($audioInputs).':duration=longest[audio_mix]';
                $mapArgs[] = '-map';
                $mapArgs[] = '[audio_mix]';

                $muxCommand = implode(' ', array_merge(
                    [escapeshellarg($ffmpeg), '-y', '-i', escapeshellarg($outputFile)],
                    $inputArgs,
                    ['-filter_complex', '"'.$filterComplex.'"'],
                    $mapArgs,
                    ['-c:v', 'copy', '-c:a', 'aac', '-b:a', '192k', '-shortest', escapeshellarg($tempOutput)]
                ));

                $muxProcess = Process::fromShellCommandline($muxCommand, timeout: 120);
                $muxProcess->run();

                if ($muxProcess->isSuccessful() && is_file($tempOutput)) {
                    @unlink($outputFile);
                    @rename($tempOutput, $outputFile);
                } else {
                    Log::warning('Audio mux failed, using video-only output', [
                        'render_id' => $render->getKey(),
                        'error' => $this->tail($muxProcess->getErrorOutput() ?: $muxProcess->getOutput()),
                    ]);
                }
            }
        }

        $finalOutput = is_file($outputFile) ? $outputFile : $tempOutput;

        $size = is_file($finalOutput) ? (filesize($finalOutput) ?: 0) : 0;
        if ($size < 100) {
            @unlink($jsonFile);
            $render->update([
                'status' => 'failed',
                'error_message' => 'Render output missing or too small.',
                'completed_at' => now(),
            ]);

            return $render->fresh();
        }

        $probe = $this->probe->probe($finalOutput);
        $expected = [
            'codec' => config('remotion.expected_codec', 'h264'),
            'width' => (int) config('remotion.expected_width', 1080),
            'height' => (int) config('remotion.expected_height', 1920),
            'fps' => (float) config('remotion.expected_fps', 30),
            'min_duration' => 0.1,
        ];
        $check = $this->probe->matches($probe, $expected);
        $requireVerification = (bool) config('remotion.require_verification', true);

        if ($requireVerification && ! $check['valid']) {
            @unlink($jsonFile);
            $render->update([
                'status' => 'failed',
                'error_message' => 'Media verification failed: '.implode(' ', $check['errors']),
                'completed_at' => now(),
            ]);

            return $render->fresh();
        }

        $render->update([
            'status' => 'completed',
            'completed_at' => now(),
            'output_path' => $relativeOutput,
            'duration' => $probe['duration'] ?? $script->target_duration ?? 30,
            'resolution' => ($probe['width'] ?? 0) > 0 && ($probe['height'] ?? 0) > 0
                ? $probe['width'].'x'.$probe['height']
                : '1080x1920',
            'fps' => $probe['fps'] ?? 30,
        ]);

        @unlink($jsonFile);

        return $render->fresh();
    }

    /**
     * Enrich scenes with source names and category for display.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    private function enrichScenes($script, array $scenes): array
    {
        $allNewsIds = [];
        foreach ($scenes as $scene) {
            $ids = is_array($scene['source_news_ids'] ?? null) ? $scene['source_news_ids'] : [];
            foreach ($ids as $id) {
                $allNewsIds[] = (int) $id;
            }
        }
        $allNewsIds = array_unique($allNewsIds);

        $newsById = [];
        if ($allNewsIds !== []) {
            $news = News::query()->whereIn('id', $allNewsIds)->get();
            foreach ($news as $n) {
                $newsById[(int) $n->getKey()] = $n;
            }
        }

        $category = null;
        $firstNews = $script->news;
        if ($firstNews instanceof News) {
            $category = $firstNews->category;
        }

        return array_map(function (array $scene) use ($newsById, $category): array {
            $ids = is_array($scene['source_news_ids'] ?? null) ? $scene['source_news_ids'] : [];
            $sources = [];
            foreach ($ids as $id) {
                $n = $newsById[(int) $id] ?? null;
                if ($n !== null && $n->source_name !== null) {
                    $sources[] = $n->source_name;
                }
            }
            $sources = array_unique(array_filter($sources));

            return array_replace($scene, [
                'source' => $sources !== [] ? implode(' • ', $sources) : null,
                'sourceUrl' => null,
                'category' => $category,
            ]);
        }, $scenes);
    }

    private function generateNarrationAudio($script, string $narration): ?AudioAsset
    {
        $audioDir = storage_path('app/public/audio');
        File::ensureDirectoryExists($audioDir);

        $ttsScript = storage_path('app/tmp/tts_generate.ps1');
        $ttsOutput = $audioDir.DIRECTORY_SEPARATOR.'tts_'.$script->getKey().'.wav';

        $psCommand = escapeshellarg($ttsScript);
        $textArg = escapeshellarg($narration);
        $outArg = escapeshellarg($ttsOutput);

        $command = sprintf(
            'powershell -NoProfile -ExecutionPolicy Bypass -File %s -Text %s -OutputPath %s',
            $psCommand,
            $textArg,
            $outArg,
        );

        $process = Process::fromShellCommandline($command, timeout: 120);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($ttsOutput)) {
            Log::warning('TTS generation failed, skipping narration audio', [
                'script_id' => $script->getKey(),
                'error' => $this->tail($process->getErrorOutput() ?: $process->getOutput()),
            ]);

            return null;
        }

        $duration = $this->probeAudioDuration($ttsOutput) ?? max(1, strlen($narration) / 15);

        $publicDisk = storage_path('app/public');
        $relative = str_replace($publicDisk.DIRECTORY_SEPARATOR, '', $ttsOutput);
        $relative = ltrim($relative, '/\\');

        $asset = AudioAsset::create([
            'video_script_id' => $script->getKey(),
            'kind' => 'narration',
            'storage_path' => $relative,
            'license_source' => 'local-tts',
            'duration_seconds' => (float) $duration,
            'loudness_lu' => null,
            'checksum_sha256' => null,
        ]);

        Log::info('TTS narration audio generated', [
            'script_id' => $script->getKey(),
            'path' => $relative,
            'duration' => $duration,
        ]);

        return $asset;
    }

    private function generateBackgroundAudio($script, float $narrationDuration): ?AudioAsset
    {
        $audioDir = storage_path('app/public/audio');
        File::ensureDirectoryExists($audioDir);

        $ffmpeg = RemotionRuntime::ffmpegPath();
        if (! $ffmpeg) {
            Log::warning('FFmpeg not available for background audio generation');

            return null;
        }

        $bgOutput = $audioDir.DIRECTORY_SEPARATOR.'bg_'.$script->getKey().'.wav';
        $duration = max(10, $narrationDuration + 5);

        $command = sprintf(
            '%s -y -f lavfi -i "sine=frequency=55:duration=%f" -f lavfi -i "sine=frequency=110:duration=%f" -filter_complex "[0:a][1:a]amix=inputs=2:duration=longest:weights=4 1[aout]" -map "[aout]" -c:a pcm_s16le -ar 22050 -ac 1 %s',
            escapeshellarg($ffmpeg),
            (float) $duration,
            (float) $duration,
            escapeshellarg($bgOutput),
        );

        $process = Process::fromShellCommandline($command, timeout: 120);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($bgOutput)) {
            Log::warning('Background audio generation failed', [
                'script_id' => $script->getKey(),
                'error' => $this->tail($process->getErrorOutput() ?: $process->getOutput()),
            ]);

            return null;
        }

        $bgDuration = $this->probeAudioDuration($bgOutput) ?? $duration;

        $publicDisk = storage_path('app/public');
        $relative = str_replace($publicDisk.DIRECTORY_SEPARATOR, '', $bgOutput);
        $relative = ltrim($relative, '/\\');

        $asset = AudioAsset::create([
            'video_script_id' => $script->getKey(),
            'kind' => 'background',
            'storage_path' => $relative,
            'license_source' => 'local-synthesized',
            'duration_seconds' => (float) $bgDuration,
            'loudness_lu' => null,
            'checksum_sha256' => null,
        ]);

        Log::info('Background audio generated', [
            'script_id' => $script->getKey(),
            'path' => $relative,
            'duration' => $bgDuration,
        ]);

        return $asset;
    }

    private function mixAudio($script, AudioAsset $narration, AudioAsset $background): ?string
    {
        $mixer = app(AudioMixService::class);
        $audioDir = storage_path('app/public/audio');
        File::ensureDirectoryExists($audioDir);

        $mixedPath = $audioDir.'/mixed_'.$script->getKey().'.wav';

        $assets = [
            [
                'kind' => 'narration',
                'storage_path' => $this->resolveStoragePath($narration->storage_path),
            ],
            [
                'kind' => 'music',
                'storage_path' => $this->resolveStoragePath($background->storage_path),
            ],
        ];

        $scenes = $script->scenes ?? [];

        try {
            $mixer->mixWithProcess($assets, $scenes, $mixedPath);
        } catch (\Throwable $e) {
            Log::warning('Audio mix failed, using narration only', [
                'script_id' => $script->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $narration->storage_path;
        }

        if (! is_file($mixedPath)) {
            return $narration->storage_path;
        }

        $publicDisk = storage_path('app/public');
        $relative = str_replace($publicDisk.DIRECTORY_SEPARATOR, '', $mixedPath);
        $relative = ltrim($relative, '/\\');

        AudioAsset::create([
            'video_script_id' => $script->getKey(),
            'kind' => 'mixed',
            'storage_path' => $relative,
            'license_source' => 'local-mixed',
            'duration_seconds' => $narration->duration_seconds,
            'loudness_lu' => null,
            'checksum_sha256' => null,
        ]);

        return $relative;
    }

    private function resolveStoragePath(string $path): string
    {
        if (RemotionRuntime::isAbsolute($path)) {
            return $path;
        }

        $publicDisk = storage_path('app/public');

        return $publicDisk.DIRECTORY_SEPARATOR.$path;
    }

    private function probeAudioDuration(string $path): ?float
    {
        $ffprobe = RemotionRuntime::ffprobePath();
        if (! $ffprobe || ! is_file($path)) {
            return null;
        }

        $process = new Process([
            $ffprobe,
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ], null, null, null, 30);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());
        $duration = (float) $output;

        return $duration > 0 ? $duration : null;
    }

    private function tail(string $value, int $limit = 1000): string
    {
        $value = trim($value);

        return $value === '' ? '(no output)' : substr($value, -$limit);
    }
}
