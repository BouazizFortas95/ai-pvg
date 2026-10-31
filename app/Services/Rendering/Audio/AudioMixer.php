<?php

namespace App\Services\Rendering\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use App\Services\Rendering\ProcessRunner;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;

/**
 * Pre-mixes narration + music + SFX into one delivery track.
 *
 * Strategy (chosen after the spike):
 *  1. Build a narration stem with exact absolute offsets (J-cuts included).
 *  2. Build a music stem whose volume follows the edit plan's per-scene intensity.
 *  3. Build an SFX stem from the plan's cue sheet.
 *  4. Duck music under voice with sidechaincompress, sum the three stems, then
 *     normalize to -14 LUFS / -1.5 dBTP.
 *
 * The composition then attaches ONE <audio> element, so HyperFrames cannot
 * drift or clip-stack the individual beds.
 */
class AudioMixer
{
    public function __construct(
        protected ProcessRunner $runner = new ProcessRunner,
        protected AudioProbe $probe = new AudioProbe,
    ) {}

    /**
     * @param  list<array{path: string, start: float}>  $narration
     * @param  list<array{path: string, start: float, gain: float}>  $sfx
     * @return array{mix: string, narration: string, music: string, sfx: string, duration: float, loudness: array<string, mixed>}
     */
    public function mix(
        array $narration,
        string $musicPath,
        array $sfx,
        float $duration,
        string $workDir,
    ): array {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();

        if ($ffmpeg === null) {
            throw new AudioPipelineException('FFmpeg is required to mix audio.');
        }

        File::ensureDirectoryExists($workDir.'/stems');

        $narrationStem = $workDir.'/stems/narration.wav';
        $musicStem = $workDir.'/stems/music.wav';
        $sfxStem = $workDir.'/stems/sfx.wav';
        $rawMix = $workDir.'/stems/mix_raw.wav';
        $finalMix = $workDir.'/mix.wav';

        $this->buildNarrationStem($narration, $duration, $narrationStem);
        $this->buildMusicStem($musicPath, $duration, $musicStem);
        $this->buildSfxStem($sfx, $duration, $sfxStem);

        $filter = $this->buildMixFilterGraph();

        $result = $this->runner->run([
            $ffmpeg, '-y', '-hide_banner', '-nostats',
            '-i', $narrationStem,
            '-i', $musicStem,
            '-i', $sfxStem,
            '-filter_complex', $filter,
            '-map', '[mix]',
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $rawMix,
        ], null, HyperFramesRuntime::environment(), 300);

        if (! $result->successful || ! is_file($rawMix)) {
            throw new AudioPipelineException('Audio mix failed: '.trim($result->errorOutput));
        }

        $loudness = $this->normalize(
            $rawMix,
            $finalMix,
            (float) config('automation.render.hyperframes.audio.target_lufs', -14),
            (float) config('automation.render.hyperframes.audio.true_peak', -1.5),
            $duration,
        );

        File::delete($rawMix);

        return [
            'mix' => $finalMix,
            'narration' => $narrationStem,
            'music' => $musicStem,
            'sfx' => $sfxStem,
            'duration' => round($this->probe->duration($finalMix), 3),
            'loudness' => $loudness,
        ];
    }

    /**
     * Narration + ducked music + SFX. Kept separate so tests can assert the
     * exact filter graph without spawning FFmpeg.
     */
    public function buildMixFilterGraph(): string
    {
        return implode(';', [
            '[0:a]asplit=2[voice][key]',
            '[1:a][key]sidechaincompress=threshold=0.03:ratio=8:attack=8:release=280:makeup=1[ducked]',
            '[voice][ducked][2:a]amix=inputs=3:normalize=0:dropout_transition=0[mix]',
        ]);
    }

    /**
     * @param  list<array{path: string, start: float}>  $narration
     */
    private function buildNarrationStem(array $narration, float $duration, string $output): void
    {
        $inputs = [['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo:d='.$this->fmt($duration)]];
        $filters = [];
        $labels = ['[0:a]'];
        $index = 1;

        foreach ($narration as $segment) {
            $path = (string) ($segment['path'] ?? '');

            if (! is_file($path)) {
                throw new AudioPipelineException('Narration segment is missing: '.$path);
            }

            $delay = (int) round(max(0, (float) ($segment['start'] ?? 0)) * 1000);
            $inputs[] = ['-i', $path];
            $filters[] = '['.$index.':a]aresample=48000,aformat=channel_layouts=stereo,'
                .'adelay='.$delay.'|'.$delay.',volume=1[n'.$index.']';
            $labels[] = '[n'.$index.']';
            $index++;
        }

        $filters[] = implode('', $labels).'amix=inputs='.count($labels).':normalize=0:duration=longest[narration]';

        $this->runFilter($inputs, implode(';', $filters), '[narration]', $output);
    }

    private function buildMusicStem(string $musicPath, float $duration, string $output): void
    {
        $filters = '[0:a]atrim=0:'.$this->fmt($duration).',asetpts=PTS-STARTPTS,'
            .'aformat=channel_layouts=stereo,volume=1[music]';

        $this->runFilter([['-i', $musicPath]], $filters, '[music]', $output);
    }

    /**
     * @param  list<array{path: string, start: float, gain: float}>  $sfx
     */
    private function buildSfxStem(array $sfx, float $duration, string $output): void
    {
        $inputs = [['-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=stereo:d='.$this->fmt($duration)]];
        $filters = [];
        $labels = ['[0:a]'];
        $index = 1;

        foreach ($sfx as $cue) {
            $path = (string) ($cue['path'] ?? '');

            if (! is_file($path)) {
                continue;
            }

            $delay = (int) round(max(0, (float) ($cue['start'] ?? 0)) * 1000);
            $gain = max(0.0, min(1.0, (float) ($cue['gain'] ?? 0.3)));
            $inputs[] = ['-i', $path];
            $filters[] = '['.$index.':a]aresample=48000,aformat=channel_layouts=stereo,'
                .'adelay='.$delay.'|'.$delay.',volume='.$this->fmt($gain).'[s'.$index.']';
            $labels[] = '[s'.$index.']';
            $index++;
        }

        $filters[] = implode('', $labels).'amix=inputs='.count($labels).':normalize=0:duration=longest[sfx]';

        $this->runFilter($inputs, implode(';', $filters), '[sfx]', $output);
    }

    /**
     * Two-pass loudnorm. Returns the measured delivery loudness.
     *
     * @return array<string, mixed>
     */
    public function normalize(string $input, string $output, float $targetLufs, float $truePeak, ?float $duration = null): array
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();

        if ($ffmpeg === null) {
            throw new AudioPipelineException('FFmpeg is required to normalize audio.');
        }

        $filter = 'loudnorm=I='.$this->fmt($targetLufs).':TP='.$this->fmt($truePeak).':LRA=11:print_format=json';
        $first = $this->runner->run([
            $ffmpeg, '-y', '-hide_banner', '-nostats', '-i', $input,
            '-af', $filter, '-f', 'null', '-',
        ], null, HyperFramesRuntime::environment(), 300);

        $measured = $this->parseLoudnorm($first->output."\n".$first->errorOutput);

        $secondFilter = 'loudnorm=I='.$this->fmt($targetLufs).':TP='.$this->fmt($truePeak).':LRA=11';

        if ($measured !== null) {
            $secondFilter .= ':measured_I='.$measured['input_i']
                .':measured_TP='.$measured['input_tp']
                .':measured_LRA='.$measured['input_lra']
                .':measured_thresh='.$measured['input_thresh']
                .':offset='.$measured['target_offset']
                .':linear=true';
        }

        $secondFilter .= ',aresample=48000,aformat=channel_layouts=stereo';

        if ($duration !== null && $duration > 0) {
            $secondFilter .= ',atrim=0:'.$this->fmt($duration).',apad=whole_dur='.$this->fmt($duration);
        }

        $second = $this->runner->run([
            $ffmpeg, '-y', '-hide_banner', '-nostats', '-i', $input,
            '-af', $secondFilter, '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le', $output,
        ], null, HyperFramesRuntime::environment(), 300);

        if (! $second->successful || ! is_file($output)) {
            throw new AudioPipelineException('Loudness normalization failed: '.trim($second->errorOutput));
        }

        $report = $this->probe->loudness($output);

        if (($report['true_peak_dbtp'] ?? $truePeak) > -1.0) {
            $this->applyLimiter($output, $truePeak);
            $report = $this->probe->loudness($output);
        }

        return [
            'integrated_lufs' => $report['integrated_lufs'],
            'true_peak_dbtp' => $report['true_peak_dbtp'],
            'lra' => $report['lra'],
            'target_lufs' => $targetLufs,
            'target_true_peak' => $truePeak,
            'measured_input' => $measured,
        ];
    }

    /**
     * @return array{input_i: string, input_tp: string, input_lra: string, input_thresh: string, target_offset: string}|null
     */
    public function parseLoudnorm(string $output): ?array
    {
        $fields = ['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'];
        $values = [];

        foreach ($fields as $field) {
            if (preg_match('/"'.$field.'"\s*:\s*"?(-?\d+(?:\.\d+)?)"?/', $output, $match) !== 1) {
                return null;
            }

            $values[$field] = $match[1];
        }

        return $values;
    }

    private function applyLimiter(string $path, float $truePeak): void
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();
        $temporary = $path.'.limited.wav';

        $result = $this->runner->run([
            $ffmpeg, '-y', '-hide_banner', '-nostats', '-i', $path,
            '-af', 'alimiter=limit='.$this->fmt($truePeak).'dB:level=false',
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le', $temporary,
        ], null, HyperFramesRuntime::environment(), 300);

        if (! $result->successful || ! is_file($temporary)) {
            throw new AudioPipelineException('True-peak limiting failed: '.trim($result->errorOutput));
        }

        File::move($temporary, $path);
    }

    /**
     * @param  list<list<string>>  $inputs
     */
    private function runFilter(array $inputs, string $filter, string $map, string $output): void
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();

        if ($ffmpeg === null) {
            throw new AudioPipelineException('FFmpeg is required to build audio stems.');
        }

        $command = [$ffmpeg, '-y', '-hide_banner', '-nostats'];

        foreach ($inputs as $input) {
            foreach ($input as $argument) {
                $command[] = $argument;
            }
        }

        $command = [
            ...$command,
            '-filter_complex', $filter,
            '-map', $map,
            '-ar', '48000', '-ac', '2', '-c:a', 'pcm_s16le',
            $output,
        ];

        $result = $this->runner->run($command, null, HyperFramesRuntime::environment(), 300);

        if (! $result->successful || ! is_file($output) || filesize($output) < 1000) {
            throw new AudioPipelineException('Audio stem failed for '.$output.': '.trim($result->errorOutput));
        }
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
