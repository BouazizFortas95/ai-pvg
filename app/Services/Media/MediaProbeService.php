<?php

namespace App\Services\Media;

use App\Support\RemotionRuntime;
use Symfony\Component\Process\Process;

class MediaProbeService
{
    /**
     * Probe a media file with FFprobe and return normalised metadata.
     *
     * @return array<string, mixed>
     */
    public function probe(string $path): array
    {
        $result = $this->emptyResult($path);

        if (! is_file($path)) {
            $result['reason'] = 'Media file does not exist.';

            return $result;
        }

        $result['size'] = filesize($path) ?: 0;

        $binary = RemotionRuntime::ffprobePath();
        if (! $binary) {
            $result['reason'] = 'FFprobe executable not found (set FFPROBE_PATH or install FFmpeg).';

            return $result;
        }

        $result['available'] = true;
        $result['ffprobe_path'] = $binary;

        $process = new Process([
            $binary,
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ], null, null, null, 60);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            $result['reason'] = 'FFprobe execution failed: '.$exception->getMessage();

            return $result;
        }

        if (! $process->isSuccessful()) {
            $result['reason'] = 'FFprobe exited '.$process->getExitCode().': '.$this->tail($process->getErrorOutput());

            return $result;
        }

        $data = json_decode(trim($process->getOutput()), true);
        if (! is_array($data)) {
            $result['reason'] = 'FFprobe returned invalid JSON.';

            return $result;
        }

        return $this->parse($data, $result);
    }

    /**
     * Normalise FFprobe JSON. Kept public for deterministic unit tests.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public function parse(array $data, array $base = []): array
    {
        $result = array_merge($this->emptyResult($base['path'] ?? ''), $base);
        $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];

        $video = null;
        $audio = null;

        foreach ($streams as $stream) {
            if (! is_array($stream)) {
                continue;
            }
            if ($video === null && ($stream['codec_type'] ?? null) === 'video') {
                $video = $stream;
            }
            if ($audio === null && ($stream['codec_type'] ?? null) === 'audio') {
                $audio = $stream;
            }
        }

        $result['streams_count'] = count($streams);
        $result['video_stream'] = $video !== null;
        $result['audio_stream'] = $audio !== null;
        $result['format'] = $format['format_name'] ?? null;
        $result['raw'] = $data;

        if ($video !== null) {
            $result['codec'] = $video['codec_name'] ?? null;
            $result['width'] = isset($video['width']) ? (int) $video['width'] : null;
            $result['height'] = isset($video['height']) ? (int) $video['height'] : null;
            $result['fps'] = $this->parseRate($video['r_frame_rate'] ?? $video['avg_frame_rate'] ?? null);
            $result['duration'] = $this->number($video['duration'] ?? null) ?? $this->number($format['duration'] ?? null);
            $result['frames'] = isset($video['nb_frames']) && is_numeric($video['nb_frames']) ? (int) $video['nb_frames'] : null;
        }

        $result['size'] = isset($format['size']) && is_numeric($format['size'])
            ? (int) $format['size']
            : ($result['size'] ?? 0);

        $result['verified'] = $video !== null
            && is_string($result['codec']) && $result['codec'] !== ''
            && ($result['width'] ?? 0) > 0
            && ($result['height'] ?? 0) > 0
            && ($result['duration'] ?? 0) > 0
            && ($result['fps'] ?? 0) > 0;

        if (! $result['verified'] && $result['reason'] === null) {
            $result['reason'] = $video === null
                ? 'No video stream found.'
                : 'Video stream metadata is incomplete.';
        }

        return $result;
    }

    /**
     * Verify probe metadata against expected properties.
     *
     * @param  array<string, mixed>  $probe
     * @param  array<string, mixed>  $expected
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function matches(array $probe, array $expected): array
    {
        $errors = [];

        if (! ($probe['verified'] ?? false)) {
            $errors[] = (string) ($probe['reason'] ?? 'Media could not be verified.');
        }

        if (($expected['require_video'] ?? true) && ! ($probe['video_stream'] ?? false)) {
            $errors[] = 'Expected a video stream.';
        }

        if (($expected['require_audio'] ?? false) && ! ($probe['audio_stream'] ?? false)) {
            $errors[] = 'Expected an audio stream.';
        }

        if (isset($expected['codec']) && $probe['codec'] !== $expected['codec']) {
            $errors[] = 'Expected codec '.$expected['codec'].', got '.($probe['codec'] ?? 'none').'.';
        }

        foreach (['width' => 'width', 'height' => 'height'] as $key => $label) {
            if (isset($expected[$key]) && (int) $probe[$key] !== (int) $expected[$key]) {
                $errors[] = 'Expected '.$label.' '.$expected[$key].', got '.($probe[$key] ?? 'none').'.';
            }
        }

        if (isset($expected['fps']) && abs((float) $probe['fps'] - (float) $expected['fps']) > 0.5) {
            $errors[] = 'Expected '.$expected['fps'].' fps, got '.($probe['fps'] ?? 'none').'.';
        }

        if (isset($expected['min_duration']) && (float) $probe['duration'] < (float) $expected['min_duration']) {
            $errors[] = 'Expected duration >= '.$expected['min_duration'].'s, got '.($probe['duration'] ?? 'none').'s.';
        }

        return [
            'valid' => $errors === [],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(string $path): array
    {
        return [
            'path' => $path,
            'available' => false,
            'verified' => false,
            'reason' => null,
            'video_stream' => false,
            'audio_stream' => false,
            'streams_count' => 0,
            'codec' => null,
            'width' => null,
            'height' => null,
            'fps' => null,
            'duration' => null,
            'frames' => null,
            'size' => is_file($path) ? (filesize($path) ?: 0) : 0,
            'format' => null,
            'ffprobe_path' => null,
            'raw' => null,
        ];
    }

    private function parseRate(mixed $rate): ?float
    {
        if (! is_string($rate) || $rate === '') {
            return null;
        }

        $parts = explode('/', $rate);
        $numerator = (float) $parts[0];
        $denominator = isset($parts[1]) ? (float) $parts[1] : 1.0;

        if ($denominator <= 0 || $numerator <= 0) {
            return null;
        }

        return round($numerator / $denominator, 3);
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function tail(string $value, int $limit = 500): string
    {
        $value = trim($value);

        return $value === '' ? '(no stderr)' : substr($value, -$limit);
    }
}
