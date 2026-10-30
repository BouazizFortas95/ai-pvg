<?php

namespace App\Services\Rendering;

use App\Enums\VideoScriptStatus;
use App\Exceptions\Rendering\HyperFramesDisabledException;
use App\Exceptions\Rendering\HyperFramesException;
use App\Exceptions\Rendering\HyperFramesLintException;
use App\Exceptions\Rendering\HyperFramesUnavailableException;
use App\Models\VideoRender;
use App\Services\Media\MediaProbeService;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Runs the HyperFrames lint -> render -> FFprobe pipeline with typed errors.
 * The feature gate fails closed before any process is spawned.
 */
class HyperFramesRenderService
{
    public function __construct(
        protected ProcessRunner $runner,
        protected MediaProbeService $probe,
        protected HyperFramesCompositionBuilder $builder,
        protected ?HyperFramesAgentAuthor $agent = null,
    ) {
        $this->agent = $agent ?? app(HyperFramesAgentAuthor::class);
    }

    public function render(VideoRender $render): VideoRender
    {
        try {
            $this->assertEnabled();
        } catch (HyperFramesDisabledException $exception) {
            return $this->fail($render, $exception);
        }

        $script = $render->videoScript()->first();

        if ($script === null) {
            return $this->fail($render, new HyperFramesException('No script associated with this render.', 'script'));
        }

        if ($script->status !== VideoScriptStatus::Approved) {
            return $this->fail($render, new HyperFramesException('Script must be approved before rendering.', 'script'));
        }

        $status = HyperFramesRuntime::status();

        if (! $status['available']) {
            return $this->fail($render, new HyperFramesUnavailableException((string) $status['reason']));
        }

        $render->update(['status' => 'rendering', 'started_at' => now(), 'completed_at' => null, 'error_message' => null]);

        try {
            $options = [
                'preset' => (string) ($render->template ?: '9:16'),
                'theme' => (string) ($render->theme ?: 'cyber'),
                'run_id' => 'render-'.$render->getKey(),
                'fps' => (int) config('automation.render.hyperframes.fps', 30),
            ];

            $built = $this->agent !== null
                ? $this->agent->authorOrFallback($script, $options)
                : $this->builder->buildFromScript($script, $options);

            $outputFile = $this->absoluteOutputPath($render);
            File::ensureDirectoryExists(dirname($outputFile));

            $lint = $this->runLint($built['projectDir']);

            if (! $lint->successful) {
                throw new HyperFramesLintException($this->tail($lint->errorOutput ?: $lint->output));
            }

            $result = $this->runRender($built['projectDir'], $outputFile);

            if (! $result->successful) {
                throw new HyperFramesException($this->tail($result->errorOutput ?: $result->output), 'render');
            }

            if (! is_file($outputFile) || (filesize($outputFile) ?: 0) < 100) {
                throw new HyperFramesException('Render output missing or too small: '.$outputFile, 'render');
            }

            $metadata = $this->probe->probe($outputFile);
            $manifest = $built['manifest'] ?? [];
            $check = $this->probe->matches($metadata, [
                'codec' => (string) config('remotion.expected_codec', 'h264'),
                'width' => (int) ($manifest['width'] ?? config('remotion.expected_width', 1080)),
                'height' => (int) ($manifest['height'] ?? config('remotion.expected_height', 1920)),
                'fps' => (float) ($manifest['fps'] ?? config('remotion.expected_fps', 30)),
                'min_duration' => 0.1,
                'require_audio' => ($manifest['audio'] ?? []) !== [],
            ]);

            if ((bool) config('remotion.require_verification', true) && ! $check['valid']) {
                throw new HyperFramesException('Media verification failed: '.implode(' ', $check['errors']), 'verify');
            }

            $render->update([
                'status' => 'completed',
                'completed_at' => now(),
                'output_path' => $this->relativeOutputPath($render),
                'duration' => $metadata['duration'] ?? $manifest['duration'] ?? $script->target_duration ?? 30,
                'resolution' => (($metadata['width'] ?? 0) > 0 && ($metadata['height'] ?? 0) > 0)
                    ? $metadata['width'].'x'.$metadata['height']
                    : (($manifest['width'] ?? 1080).'x'.($manifest['height'] ?? 1920)),
                'fps' => $metadata['fps'] ?? $manifest['fps'] ?? 30,
            ]);

            return $render->fresh();
        } catch (HyperFramesException $exception) {
            Log::warning('HyperFrames render failed', [
                'render_id' => $render->getKey(),
                'stage' => $exception->stage,
                'error' => $exception->getMessage(),
            ]);

            return $this->fail($render, $exception);
        } catch (\Throwable $exception) {
            Log::error('HyperFrames render crashed', [
                'render_id' => $render->getKey(),
                'error' => $exception->getMessage(),
            ]);

            return $this->fail($render, new HyperFramesException($exception->getMessage(), 'render'));
        }
    }

    /**
     * Lint then render a prebuilt composition (verification command path).
     * The feature gate is intentionally bypassed for explicit operator
     * verification, but lint and FFprobe verification still apply.
     *
     * @param  array{html: string, projectDir: string, compositionId: string, runId: string, manifest: array<string, mixed>}  $built
     * @return array{lint: ProcessResult, render: ProcessResult, metadata: array<string, mixed>, output: string}
     */
    public function lintAndRender(array $built, string $outputFile): array
    {
        $lint = $this->runLint($built['projectDir']);

        if (! $lint->successful) {
            throw new HyperFramesLintException($this->tail($lint->errorOutput ?: $lint->output));
        }

        File::ensureDirectoryExists(dirname($outputFile));
        $render = $this->runRender($built['projectDir'], $outputFile);

        if (! $render->successful) {
            throw new HyperFramesException($this->tail($render->errorOutput ?: $render->output), 'render');
        }

        if (! is_file($outputFile) || (filesize($outputFile) ?: 0) < 100) {
            throw new HyperFramesException('Render output missing or too small: '.$outputFile, 'render');
        }

        return [
            'lint' => $lint,
            'render' => $render,
            'metadata' => $this->probe->probe($outputFile),
            'output' => $outputFile,
        ];
    }

    public function runLint(string $projectDir): ProcessResult
    {
        return $this->runner->run(
            [...HyperFramesRuntime::commandPrefix(), 'lint'],
            $projectDir,
            HyperFramesRuntime::environment(),
            180,
        );
    }

    public function runRender(string $projectDir, string $outputFile): ProcessResult
    {
        return $this->runner->run(
            [
                ...HyperFramesRuntime::commandPrefix(),
                'render',
                '--quality', (string) config('automation.render.hyperframes.quality', 'looks'),
                '--fps', (string) config('automation.render.hyperframes.fps', 30),
                '--output', $outputFile,
            ],
            $projectDir,
            HyperFramesRuntime::environment(),
            (int) config('automation.render.hyperframes.timeout', 600),
        );
    }

    /**
     * @return array{available: bool, reason: ?string, enabled: bool, flag: string, flag_enabled: bool, cli: ?string, node: ?string, ffmpeg: ?string, ffprobe: ?string, directory: string, output: string}
     */
    public function checkAvailability(): array
    {
        $status = HyperFramesRuntime::status();

        if (! $status['enabled']) {
            $status['available'] = false;
            $status['reason'] = 'HyperFrames rendering is disabled (automation.render.hyperframes.enabled or feature flag '.$status['flag'].').';
        }

        return $status;
    }

    public function assertEnabled(): void
    {
        if (! HyperFramesRuntime::enabled()) {
            throw new HyperFramesDisabledException;
        }
    }

    public function absoluteOutputPath(VideoRender $render): string
    {
        $configured = (string) config('automation.render.hyperframes.output_video_dir', storage_path('app/video_out'));
        $directory = HyperFramesRuntime::isAbsolute($configured) ? $configured : base_path($configured);

        return rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'render_'.$render->getKey().'_hf.mp4';
    }

    public function relativeOutputPath(VideoRender $render): string
    {
        $absolute = $this->absoluteOutputPath($render);
        $base = rtrim(base_path(), '/\\').DIRECTORY_SEPARATOR;

        if (str_starts_with($absolute, $base)) {
            return str_replace('\\', '/', substr($absolute, strlen($base)));
        }

        return $absolute;
    }

    protected function fail(VideoRender $render, HyperFramesException $exception): VideoRender
    {
        $render->update([
            'status' => 'failed',
            'error_message' => '['.$exception->stage.'] '.$exception->getMessage(),
            'completed_at' => now(),
        ]);

        return $render->fresh();
    }

    protected function tail(string $value, int $limit = 2000): string
    {
        $value = trim($value);

        return $value === '' ? '(no output)' : substr($value, -$limit);
    }
}
