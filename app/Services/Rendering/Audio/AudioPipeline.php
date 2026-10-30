<?php

namespace App\Services\Rendering\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use Illuminate\Support\Facades\File;

/**
 * Orchestrates narration -> transcription -> music/SFX -> mix for one edit
 * plan. Deterministic given the same plan and voices.
 */
class AudioPipeline
{
    public function __construct(
        protected TtsGenerator $tts = new TtsGenerator,
        protected TranscriptionService $transcriber = new TranscriptionService,
        protected MusicBedGenerator $music = new MusicBedGenerator,
        protected SfxGenerator $sfx = new SfxGenerator,
        protected AudioMixer $mixer = new AudioMixer,
        protected AudioProbe $probe = new AudioProbe,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return array{
     *     mix: string,
     *     duration: float,
     *     word_timings: list<array{word: string, startMs: int, endMs: int}>,
     *     tts_provider: string,
     *     voice: string,
     *     transcription: array<string, mixed>,
     *     warn: list<string>
     * }
     */
    public function build(array $plan, string $workDir, ?string $voice = null): array
    {
        File::ensureDirectoryExists($workDir.'/narration');
        File::ensureDirectoryExists($workDir.'/sfx');

        $warnings = [];
        $narrationSegments = [];
        $provider = null;
        $selectedVoice = null;
        $language = (string) ($plan['language'] ?? 'en');

        foreach (is_array($plan['scenes'] ?? null) ? $plan['scenes'] : [] as $index => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $line = trim((string) ($scene['narration'] ?? ''));

            if ($line === '') {
                continue;
            }

            $sceneId = (string) ($scene['id'] ?? 'scene-'.($index + 1));
            $path = $workDir.'/narration/'.$sceneId.'.wav';

            $tts = $this->tts->synthesize($line, $language, $voice, $path);
            $provider ??= $tts['provider'];
            $selectedVoice ??= $tts['voice'];

            $sceneDuration = (float) ($scene['duration'] ?? 0);
            $start = (float) ($scene['narration_start'] ?? $scene['start'] ?? 0);

            if ($tts['duration'] > $sceneDuration + 0.25) {
                throw new AudioPipelineException(
                    'Narration for '.$sceneId.' is '.$tts['duration'].'s but the scene is '.$sceneDuration.'s. '
                    .'Shorten the line or lengthen the scene before rendering.'
                );
            }

            if ($start + $tts['duration'] > (float) $plan['duration'] + 0.1) {
                throw new AudioPipelineException('Narration for '.$sceneId.' runs past the end of the edit plan.');
            }

            $narrationSegments[] = [
                'path' => $path,
                'start' => $start,
                'duration' => $tts['duration'],
                'scene_id' => $sceneId,
            ];
        }

        if ($narrationSegments === []) {
            throw new AudioPipelineException('The edit plan has no narration lines; audio is required.');
        }

        $music = $this->music->generate($plan, $workDir.'/music.wav');

        $sfxCues = [];

        foreach (is_array($plan['scenes'] ?? null) ? $plan['scenes'] : [] as $scene) {
            foreach (is_array($scene['sfx'] ?? null) ? $scene['sfx'] : [] as $cue) {
                if (! is_array($cue)) {
                    continue;
                }

                $name = (string) ($cue['cue'] ?? '');
                $time = max(0, (float) ($cue['time'] ?? 0));

                if ($name === '') {
                    continue;
                }

                $sfxCues[] = [
                    'cue' => $name,
                    'time' => $time,
                    'gain' => (float) ($cue['gain'] ?? 0.3),
                ];
            }
        }

        $sfxFiles = [];

        foreach (array_values(array_unique(array_column($sfxCues, 'cue'))) as $cue) {
            $sfxFiles[$cue] = $this->sfx->generate($cue, $workDir.'/sfx/'.$cue.'.wav');
        }

        $mix = $this->mixer->mix(
            array_map(
                static fn (array $segment): array => ['path' => $segment['path'], 'start' => $segment['start']],
                $narrationSegments,
            ),
            $music['path'],
            array_map(
                static fn (array $cue): array => [
                    'path' => $sfxFiles[$cue['cue']],
                    'start' => $cue['time'],
                    'gain' => $cue['gain'],
                ],
                $sfxCues,
            ),
            (float) $plan['duration'],
            $workDir,
        );

        $transcription = $this->transcriber->transcribe(
            $mix['narration'],
            $language,
            $workDir,
        );

        if (! $transcription['ok']) {
            $warnings[] = 'Captions omitted: '.($transcription['reason'] ?? 'transcription unavailable').'.';
        }

        $wordTimings = $transcription['words'];

        if ($wordTimings !== []) {
            $last = end($wordTimings);

            if ((int) $last['endMs'] > (int) round((float) $plan['duration'] * 1000) + 50) {
                $warnings[] = 'Transcript runs past the edit plan; trailing words were dropped.';
                $limit = (int) round((float) $plan['duration'] * 1000);
                $wordTimings = array_values(array_filter(
                    $wordTimings,
                    static fn (array $word): bool => $word['startMs'] < $limit,
                ));
            }
        }

        return [
            'mix' => $mix['mix'],
            'duration' => $mix['duration'],
            'word_timings' => $wordTimings,
            'tts_provider' => (string) $provider,
            'voice' => (string) $selectedVoice,
            'transcription' => $transcription,
            'warn' => $warnings,
            'loudness' => $mix['loudness'] ?? null,
        ];
    }
}
