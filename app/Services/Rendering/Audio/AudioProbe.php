<?php

namespace App\Services\Rendering\Audio;

use App\Services\Rendering\ProcessRunner;
use App\Support\HyperFramesRuntime;
use RuntimeException;

/**
 * FFmpeg/FFprobe helpers for the audio pipeline and QA gates. All parsing is
 * pure and covered by fixture tests.
 */
class AudioProbe
{
    public function __construct(
        protected ProcessRunner $runner = new ProcessRunner,
    ) {}

    /**
     * @return array{streams: list<array<string, mixed>>, format: array<string, mixed>}
     */
    public function probe(string $path): array
    {
        $ffprobe = HyperFramesRuntime::ffprobePath();

        if ($ffprobe === null) {
            throw new RuntimeException('FFprobe is not available.');
        }

        $result = $this->runner->run([
            $ffprobe, '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $path,
        ], null, HyperFramesRuntime::environment(), 60);

        if (! $result->successful || trim($result->output) === '') {
            throw new RuntimeException('FFprobe failed for '.$path.': '.trim($result->errorOutput));
        }

        $data = json_decode($result->output, true);

        if (! is_array($data)) {
            throw new RuntimeException('FFprobe returned invalid JSON for '.$path);
        }

        return [
            'streams' => is_array($data['streams'] ?? null) ? $data['streams'] : [],
            'format' => is_array($data['format'] ?? null) ? $data['format'] : [],
        ];
    }

    /**
     * @return array{audio: ?array<string, mixed>, video: ?array<string, mixed>, duration: float}
     */
    public function streams(string $path): array
    {
        $probe = $this->probe($path);
        $audio = null;
        $video = null;

        foreach ($probe['streams'] as $stream) {
            if ($audio === null && ($stream['codec_type'] ?? null) === 'audio') {
                $audio = $stream;
            }

            if ($video === null && ($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;
            }
        }

        return [
            'audio' => $audio,
            'video' => $video,
            'duration' => (float) ($probe['format']['duration'] ?? $video['duration'] ?? $audio['duration'] ?? 0),
        ];
    }

    public function hasAudio(string $path): bool
    {
        return $this->streams($path)['audio'] !== null;
    }

    public function duration(string $path): float
    {
        return $this->streams($path)['duration'];
    }

    /**
     * Measure integrated loudness and true peak with FFmpeg ebur128.
     *
     * @return array{integrated_lufs: ?float, true_peak_dbtp: ?float, lra: ?float, raw: string}
     */
    public function loudness(string $path): array
    {
        $ffmpeg = $this->requireFfmpeg();

        $result = $this->runner->run([
            $ffmpeg, '-hide_banner', '-nostats', '-i', $path,
            '-af', 'ebur128=peak=true:framelog=quiet', '-f', 'null', '-',
        ], null, HyperFramesRuntime::environment(), 300);

        $output = $result->output."\n".$result->errorOutput;

        return $this->parseLoudness($output);
    }

    /**
     * @return array{integrated_lufs: ?float, true_peak_dbtp: ?float, lra: ?float, raw: string}
     */
    public function parseLoudness(string $output): array
    {
        $integrated = null;
        $peak = null;
        $lra = null;

        if (preg_match('/I:\s*(-?\d+(?:\.\d+)?)\s*LUFS/i', $output, $match) === 1) {
            $integrated = (float) $match[1];
        }

        if (preg_match('/Peak:\s*(-?\d+(?:\.\d+)?)\s*dBFS/i', $output, $match) === 1) {
            $peak = (float) $match[1];
        }

        if (preg_match('/LRA:\s*(-?\d+(?:\.\d+)?)\s*LU/i', $output, $match) === 1) {
            $lra = (float) $match[1];
        }

        return [
            'integrated_lufs' => $integrated,
            'true_peak_dbtp' => $peak,
            'lra' => $lra,
            'raw' => $output,
        ];
    }

    /**
     * @return array{mean_volume: ?float, max_volume: ?float}
     */
    public function volume(string $path): array
    {
        $ffmpeg = $this->requireFfmpeg();

        $result = $this->runner->run([
            $ffmpeg, '-hide_banner', '-nostats', '-i', $path,
            '-vn', '-af', 'volumedetect', '-f', 'null', '-',
        ], null, HyperFramesRuntime::environment(), 300);

        return $this->parseVolume($result->output."\n".$result->errorOutput);
    }

    /**
     * @return array{mean_volume: ?float, max_volume: ?float}
     */
    public function parseVolume(string $output): array
    {
        $mean = null;
        $max = null;

        if (preg_match('/mean_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/i', $output, $match) === 1) {
            $mean = (float) $match[1];
        }

        if (preg_match('/max_volume:\s*(-?\d+(?:\.\d+)?)\s*dB/i', $output, $match) === 1) {
            $max = (float) $match[1];
        }

        return ['mean_volume' => $mean, 'max_volume' => $max];
    }

    /**
     * @return list<array{start: float, end: float, duration: float}>
     */
    public function silences(string $path, float $thresholdDb = -45.0, float $minDuration = 0.7): array
    {
        $ffmpeg = $this->requireFfmpeg();

        $result = $this->runner->run([
            $ffmpeg, '-hide_banner', '-nostats', '-i', $path,
            '-vn', '-af', 'silencedetect=noise='.$thresholdDb.'dB:d='.$minDuration,
            '-f', 'null', '-',
        ], null, HyperFramesRuntime::environment(), 300);

        return $this->parseSilences($result->output."\n".$result->errorOutput);
    }

    /**
     * @return list<array{start: float, end: float, duration: float}>
     */
    public function parseSilences(string $output): array
    {
        $silences = [];
        $start = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/silence_start:\s*(-?\d+(?:\.\d+)?)/', $line, $match) === 1) {
                $start = (float) $match[1];
            }

            if (preg_match('/silence_end:\s*(-?\d+(?:\.\d+)?)/', $line, $match) === 1) {
                $end = (float) $match[1];
                $begin = $start ?? 0.0;
                $silences[] = [
                    'start' => round($begin, 3),
                    'end' => round($end, 3),
                    'duration' => round($end - $begin, 3),
                ];
                $start = null;
            }
        }

        return $silences;
    }

    public function requireFfmpeg(): string
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();

        if ($ffmpeg === null) {
            throw new RuntimeException('FFmpeg is not available.');
        }

        return $ffmpeg;
    }
}
