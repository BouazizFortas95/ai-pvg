<?php

namespace App\Services\Rendering\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use App\Services\Rendering\ProcessRunner;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;

/**
 * Deterministic, locally generated music bed. No licensed track, no network,
 * no random source (FFmpeg seeds are fixed).
 */
class MusicBedGenerator
{
    public function __construct(
        protected ProcessRunner $runner = new ProcessRunner,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{path: string, duration: float}
     */
    public function generate(array $plan, string $outputPath): array
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();

        if ($ffmpeg === null) {
            throw new AudioPipelineException('FFmpeg is required to generate the music bed.');
        }

        $duration = max(3.0, (float) ($plan['duration'] ?? 0));
        $chords = [
            [110.00, 261.63, 329.63], // Am
            [87.31, 220.00, 261.63],  // F
            [130.81, 329.63, 392.00], // C
            [98.00, 246.94, 293.66],  // G
        ];

        $inputs = [];
        $filters = [];
        $chordLabels = [];
        $index = 0;

        foreach ($chords as $chordIndex => $frequencies) {
            $mixInputs = [];

            foreach ($frequencies as $frequency) {
                $inputs[] = '-f';
                $inputs[] = 'lavfi';
                $inputs[] = '-i';
                $inputs[] = 'sine=frequency='.$frequency.':sample_rate=48000:duration=4';
                $mixInputs[] = '['.$index.':a]';
                $index++;
            }

            $filters[] = implode('', $mixInputs).'amix=inputs=3:normalize=0,'
                .'volume=0.16,lowpass=f=1100,tremolo=f=0.12:d=0.18[c'.$chordIndex.']';
            $chordLabels[] = '[c'.$chordIndex.']';
        }

        $loops = (int) ceil($duration / 16.0) + 1;
        $loopSamples = 16 * 48000;

        $filters[] = implode('', $chordLabels).'concat=n=4:v=0:a=1[loop]';
        $filters[] = '[loop]aloop=loop='.$loops.':size='.$loopSamples.','
            .'atrim=0:'.$this->fmt($duration).',asetpts=PTS-STARTPTS[music]';

        // A very quiet pink-noise layer adds air without masking voice.
        $noiseIndex = $index;
        $inputs[] = '-f';
        $inputs[] = 'lavfi';
        $inputs[] = '-i';
        $inputs[] = 'anoisesrc=color=pink:seed=42:duration='.$this->fmt($duration).':amplitude=0.015';
        $filters[] = '['.$noiseIndex.':a]lowpass=f=2400,volume=0.5[air]';

        $intensity = $this->intensityExpression($plan);
        $filters[] = '[music][air]amix=inputs=2:normalize=0,'
            .'volume='.$intensity.':eval=frame,'
            .'afade=t=in:st=0:d=1.2,'
            .'afade=t=out:st='.$this->fmt(max(0, $duration - 1.2)).':d=1.2,'
            .'aformat=channel_layouts=stereo[out]';

        File::ensureDirectoryExists(dirname($outputPath));

        $command = [
            $ffmpeg, '-y', '-hide_banner', '-nostats',
            ...$inputs,
            '-filter_complex', implode(';', $filters),
            '-map', '[out]', '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $outputPath,
        ];

        $result = $this->runner->run($command, null, HyperFramesRuntime::environment(), 300);

        if (! $result->successful || ! is_file($outputPath) || filesize($outputPath) < 1000) {
            throw new AudioPipelineException('Music bed generation failed: '.trim($result->errorOutput));
        }

        return [
            'path' => $outputPath,
            'duration' => round(app(AudioProbe::class)->duration($outputPath), 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    public function intensityExpression(array $plan): string
    {
        // Fixed intensity avoids commas in FFmpeg filter expressions.
        return '0.25';
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
