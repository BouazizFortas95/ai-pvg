<?php

namespace App\Console\Commands;

use App\Services\Media\MediaProbeService;
use App\Support\RemotionRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class VerifyMp4Command extends Command
{
    protected $signature = 'ai-pvg:verify-mp4
        {--payload=remotion/payload.json : JSON payload for the synthetic render}
        {--output= : MP4 output path (defaults to storage/app/video_out/verify_<timestamp>.mp4)}
        {--execute : Run a real render; without this flag only preflight checks run}';

    protected $description = 'Opt-in real MP4 verification — preflight by default, --execute to render';

    public function handle(MediaProbeService $probe): int
    {
        $this->warn('=== OPT-IN REAL MP4 VERIFICATION ===');

        $chrome = RemotionRuntime::chromePath();
        $ffprobe = RemotionRuntime::ffprobePath();
        $ffmpeg = RemotionRuntime::ffmpegPath();
        $cli = RemotionRuntime::cliPath();
        $payload = $this->resolvePath((string) $this->option('payload'));
        $output = $this->option('output')
            ? $this->resolvePath((string) $this->option('output'))
            : storage_path('app/video_out/verify_'.date('Ymd_His').'.mp4');

        $this->line('Chrome: '.($chrome ?? 'MISSING'));
        $this->line('FFprobe: '.($ffprobe ?? 'MISSING'));
        $this->line('FFmpeg: '.($ffmpeg ?? 'MISSING'));
        $this->line('Remotion CLI: '.$cli);
        $this->line('Payload: '.$payload);
        $this->line('Output: '.$output);

        $missing = [];

        if (! $chrome) {
            $missing[] = 'Chrome headless is missing. Set REMOTION_CHROME_EXECUTABLE or install Chrome.';
        }
        if (! $ffprobe) {
            $missing[] = 'FFprobe is missing. Set FFPROBE_PATH or install FFmpeg.';
        }
        if (! is_file($cli)) {
            $missing[] = 'Remotion CLI is missing: '.$cli.' (run the Remotion build first).';
        }
        if (! is_file($payload)) {
            $missing[] = 'Payload is missing: '.$payload;
        }

        $outputDir = dirname($output);
        if (! is_dir($outputDir) && ! File::makeDirectory($outputDir, 0755, true) && ! is_dir($outputDir)) {
            $missing[] = 'Output directory is not writable: '.$outputDir;
        }

        if (! $ffmpeg) {
            $this->warn('FFmpeg was not found; media verification will still run.');
        }

        if ($missing !== []) {
            foreach ($missing as $message) {
                $this->error($message);
            }

            return 1;
        }

        if (! $this->option('execute')) {
            $this->info('Preflight passed. Re-run with --execute to perform a real render and FFprobe verification.');

            return 0;
        }

        $logFile = $output.'.log';
        File::ensureDirectoryExists(dirname($logFile));
        file_put_contents($logFile, '');

        $process = new Process([
            RemotionRuntime::nodeBinary(),
            $cli,
            '--input', $payload,
            '--output', $output,
        ], RemotionRuntime::directory(), RemotionRuntime::environment(), null, (int) config('remotion.timeout', 600));

        $this->info('Starting Remotion render...');

        try {
            $process->run(function (string $type, string $buffer) use ($logFile): void {
                file_put_contents($logFile, $buffer, FILE_APPEND);
            });
        } catch (\Throwable $exception) {
            $this->error('Remotion process failed to start: '.$exception->getMessage());

            return 1;
        }

        if (! $process->isSuccessful()) {
            $this->error('Remotion CLI failed (exit '.(int) $process->getExitCode().'). Log: '.$logFile);
            $this->line($this->tail($process->getErrorOutput() ?: $process->getOutput()));

            return 1;
        }

        if (! is_file($output) || (filesize($output) ?: 0) < 100) {
            $this->error('Output MP4 missing or too small: '.$output);

            return 1;
        }

        $payloadData = json_decode((string) file_get_contents($payload), true);
        $expectAudio = is_array($payloadData)
            && (! empty($payloadData['audioUrl']) || ! empty($payloadData['audio_url']));

        $metadata = $probe->probe($output);
        $check = $probe->matches($metadata, [
            'codec' => config('remotion.expected_codec', 'h264'),
            'width' => (int) config('remotion.expected_width', 1080),
            'height' => (int) config('remotion.expected_height', 1920),
            'fps' => (float) config('remotion.expected_fps', 30),
            'min_duration' => 0.1,
            'require_audio' => $expectAudio,
        ]);

        $this->line('File size: '.filesize($output).' bytes');
        $this->line('FFprobe available: '.($metadata['available'] ? 'YES' : 'NO'));
        $this->line('Video stream: '.($metadata['video_stream'] ? 'YES' : 'NO'));
        $this->line('Audio stream: '.($metadata['audio_stream'] ? 'YES' : 'NO'));
        $this->line('Codec: '.($metadata['codec'] ?? 'none'));
        $this->line('Resolution: '.($metadata['width'] ?? '?').'x'.($metadata['height'] ?? '?'));
        $this->line('Frame rate: '.($metadata['fps'] ?? '?').' fps');
        $this->line('Duration: '.($metadata['duration'] ?? '?').' seconds');
        $this->line('Frames: '.($metadata['frames'] ?? '?'));

        if (! $check['valid']) {
            foreach ($check['errors'] as $error) {
                $this->error($error);
            }
            $this->error('MP4 verification FAILED. Output kept for inspection: '.$output);

            return 1;
        }

        $this->info('MP4 VERIFIED: '.$output);
        $this->line('FFprobe command: '.(string) $metadata['ffprobe_path'].' -v error -show_format -show_streams '.$output);

        return 0;
    }

    private function resolvePath(string $path): string
    {
        return RemotionRuntime::isAbsolute($path) ? $path : base_path($path);
    }

    private function tail(string $value, int $limit = 1500): string
    {
        $value = trim($value);

        return $value === '' ? '(no output)' : substr($value, -$limit);
    }
}
