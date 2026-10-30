<?php

namespace App\Services\Rendering\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use App\Services\Rendering\ProcessRunner;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;

/**
 * Deterministic locally synthesized SFX. No sample library and no network.
 */
class SfxGenerator
{
    public function __construct(
        protected ProcessRunner $runner = new ProcessRunner,
    ) {}

    public function generate(string $cue, string $outputPath): string
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();

        if ($ffmpeg === null) {
            throw new AudioPipelineException('FFmpeg is required to generate SFX.');
        }

        [$source, $duration, $filter] = match ($cue) {
            'whoosh' => [
                'anoisesrc=color=white:seed=7:duration=0.45:amplitude=0.55',
                0.45,
                'highpass=f=250,lowpass=f=7000,afade=t=in:st=0:d=0.06,afade=t=out:st=0.28:d=0.17,volume=0.7',
            ],
            'hit' => [
                'aevalsrc=0.65*sin(2*PI*72*t)*exp(-22*t)+0.22*sin(2*PI*190*t)*exp(-38*t):s=48000:d=0.28',
                0.28,
                'afade=t=out:st=0.12:d=0.16,volume=0.9',
            ],
            'riser' => [
                'aevalsrc=0.28*sin(2*PI*(160*t+380*t*t))*exp(-0.35*t):s=48000:d=0.95',
                0.95,
                'afade=t=in:st=0:d=0.12,afade=t=out:st=0.55:d=0.4,volume=0.65',
            ],
            default => throw new AudioPipelineException('Unknown SFX cue: '.$cue),
        };

        File::ensureDirectoryExists(dirname($outputPath));

        $result = $this->runner->run([
            $ffmpeg, '-y', '-hide_banner', '-nostats',
            '-f', 'lavfi', '-i', $source,
            '-af', $filter.',aformat=channel_layouts=stereo',
            '-ar', '48000', '-ac', '2', '-t', $this->fmt($duration), '-c:a', 'pcm_s16le',
            $outputPath,
        ], null, HyperFramesRuntime::environment(), 120);

        if (! $result->successful || ! is_file($outputPath) || filesize($outputPath) < 500) {
            throw new AudioPipelineException('SFX generation failed for '.$cue.': '.trim($result->errorOutput));
        }

        return $outputPath;
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
