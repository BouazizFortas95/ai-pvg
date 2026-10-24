<?php

namespace App\Services;

use App\Enums\VideoScriptStatus;
use App\Models\VideoRender;
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

        $payload = [
            'scenes' => $scenes,
            'title' => $script->title,
            'language' => $script->language,
            'target_duration' => $script->target_duration,
            'template' => $render->template,
        ];

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

        $probe = $this->probe->probe($outputFile);
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

    private function tail(string $value, int $limit = 1000): string
    {
        $value = trim($value);

        return $value === '' ? '(no output)' : substr($value, -$limit);
    }
}
