<?php

namespace App\Services\Rendering\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use Illuminate\Support\Facades\File;

/**
 * Builds the canonical single-source-of-truth audio_timeline.json manifest.
 *
 * Rules:
 *  - Narration durations are measured with ffprobe (never estimated).
 *  - Scene durations are derived from narration durations + padding (never the reverse).
 *  - Total video duration = narration end + outro padding (0.8–1.5s).
 *  - Music covers the exact total duration with ducking.
 *  - Pre-mixed into one mix.wav padded/trimmed to exact total duration.
 */
class AudioTimelineBuilder
{
    public function __construct(
        protected TtsGenerator $tts = new TtsGenerator,
        protected TranscriptionService $transcriber = new TranscriptionService,
        protected MusicBedGenerator $music = new MusicBedGenerator,
        protected SfxGenerator $sfx = new SfxGenerator,
        protected AudioMixer $mixer = new AudioMixer,
        protected AudioProbe $probe = new AudioProbe,
        protected AudioTimelineValidator $validator = new AudioTimelineValidator,
    ) {}

    /**
     * Build the audio timeline from an input plan/payload.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function build(array $input, string $workDir, ?string $voice = null): array
    {
        File::ensureDirectoryExists($workDir.'/narration');
        File::ensureDirectoryExists($workDir.'/sfx');
        File::ensureDirectoryExists($workDir.'/stems');

        $language = (string) ($input['language'] ?? 'en');
        $rawScenes = is_array($input['scenes'] ?? null) ? $input['scenes'] : [];

        if (empty($rawScenes)) {
            throw new AudioPipelineException('Cannot build audio timeline: no scenes provided.');
        }

        // 1. Synthesize narration for each scene and measure exact durations with ffprobe
        $narrationSegments = [];
        $provider = null;
        $selectedVoice = null;

        foreach ($rawScenes as $index => $scene) {
            $sceneId = (string) ($scene['id'] ?? 'scene-'.($index + 1));
            $text = trim((string) ($scene['narration'] ?? ''));

            if ($text === '') {
                $text = trim((string) ($scene['description'] ?? ''));
            }
            if ($text === '') {
                $text = (string) ($scene['headline'] ?? $input['title'] ?? 'Scene '.($index + 1));
            }

            $path = $workDir.'/narration/'.$sceneId.'.wav';
            $ttsResult = $this->tts->synthesize($text, $language, $voice, $path);

            $provider ??= $ttsResult['provider'];
            $selectedVoice ??= $ttsResult['voice'];

            // MEASURED duration via ffprobe (never estimated)
            $measuredDuration = round($this->probe->duration($path), 3);

            if ($measuredDuration <= 0.05) {
                throw new AudioPipelineException("Synthesized audio for {$sceneId} is empty or unmeasurable.");
            }

            $narrationSegments[] = [
                'scene_id' => $sceneId,
                'path' => $path,
                'duration' => $measuredDuration,
                'text' => $text,
                'original_scene' => $scene,
            ];
        }

        // 2. Sequence narration segments with deliberate gaps (0.15 - 0.4s)
        // Scene durations are DERIVED from narration durations + padding
        $gap = 0.22; // deliberate gap between consecutive narration lines
        $outroPad = 1.0; // deliberate outro tail padding (0.8 - 1.5s)

        $timelineNarration = [];
        $timelineScenes = [];
        $timelineCursor = 0.0;

        foreach ($narrationSegments as $index => $item) {
            $sceneId = $item['scene_id'];
            $duration = $item['duration'];

            $start = round($timelineCursor, 3);
            $end = round($start + $duration, 3);

            $timelineNarration[] = [
                'scene_id' => $sceneId,
                'path' => $item['path'],
                'duration' => $duration,
                'start' => $start,
                'end' => $end,
                'text' => $item['text'],
            ];

            // Scene duration: duration of speech + gap (or outro padding for the final scene)
            $isLast = $index === count($narrationSegments) - 1;
            $sceneDuration = $isLast ? round($duration + $outroPad, 3) : round($duration + $gap, 3);

            // J-cut: audio leads picture by 0.2s on ~30% of cuts (index % 3 === 1)
            $narrationStart = $start;
            if ($index > 0 && $index % 3 === 1) {
                $narrationStart = round(max(0, $start - 0.2), 3);
            }

            $orig = $item['original_scene'];
            $timelineScenes[] = [
                ...$orig,
                'id' => $sceneId,
                'order' => $index + 1,
                'start' => $start,
                'duration' => $sceneDuration,
                'end' => round($start + $sceneDuration, 3),
                'narration' => $item['text'],
                'narration_start' => $narrationStart,
                'narration_duration' => $duration,
            ];

            $timelineCursor = round($timelineCursor + $sceneDuration, 3);
        }

        $totalVideoDuration = round($timelineCursor, 3);

        // 3. Music bed: trimmed/looped to exact total duration, covers 0.0 to totalVideoDuration
        $musicFile = $workDir.'/music.wav';
        $this->music->generate([
            'duration' => $totalVideoDuration,
            'preset' => $input['preset'] ?? '9:16',
            'theme' => $input['theme'] ?? 'cyber',
        ], $musicFile);

        $musicMeasured = round($this->probe->duration($musicFile), 3);

        $musicTimeline = [
            'id' => 'music-bed-1',
            'path' => $musicFile,
            'start' => 0.0,
            'end' => $totalVideoDuration,
            'duration' => $totalVideoDuration,
            'measured_duration' => $musicMeasured,
            'volume' => 0.14,
            'fade_in' => 0.5,
            'fade_out' => 1.5,
            'ducking_db' => -14.0,
        ];

        // 4. SFX: tie to scene transition/reveal points
        $sfxCues = [];
        $sfxFiles = [];

        foreach ($timelineScenes as $scene) {
            $sceneSfx = is_array($scene['sfx'] ?? null) ? $scene['sfx'] : [];
            $sfxCue = $scene['directives']['sfx_cue'] ?? null;

            if ($sfxCue && empty($sceneSfx)) {
                $sceneSfx[] = ['cue' => (string) $sfxCue, 'time' => $scene['start'], 'gain' => 0.35];
            }

            foreach ($sceneSfx as $cue) {
                if (! is_array($cue)) {
                    continue;
                }

                $name = trim((string) ($cue['cue'] ?? ''));
                $time = max(0, (float) ($cue['time'] ?? $scene['start']));

                if ($name === '' || strtolower($name) === 'none') {
                    continue;
                }

                $sfxCues[] = [
                    'cue' => $name,
                    'time' => $time,
                    'gain' => (float) ($cue['gain'] ?? 0.35),
                ];
            }
        }

        // Generate SFX wave files
        foreach (array_values(array_unique(array_column($sfxCues, 'cue'))) as $cueName) {
            $sfxFiles[$cueName] = $this->sfx->generate($cueName, $workDir.'/sfx/'.$cueName.'.wav');
        }

        $timelineSfx = array_map(function (array $cue) use ($sfxFiles): array {
            $cueName = $cue['cue'];
            $file = $sfxFiles[$cueName] ?? '';

            return [
                'cue' => $cueName,
                'path' => $file,
                'time' => (float) $cue['time'],
                'gain' => (float) $cue['gain'],
            ];
        }, $sfxCues);

        // 5. Pre-mix with FFmpeg into ONE mix.wav padded/trimmed to exact video duration
        $mixResult = $this->mixer->mix(
            array_map(fn ($seg) => ['path' => $seg['path'], 'start' => $seg['start']], $timelineNarration),
            $musicFile,
            array_map(fn ($cue) => [
                'path' => $cue['path'],
                'start' => $cue['time'],
                'gain' => $cue['gain'],
            ], $timelineSfx),
            $totalVideoDuration,
            $workDir,
        );

        // 6. Transcribe narration for truthful word-pop captions
        $transcription = $this->transcriber->transcribe(
            $mixResult['narration'],
            $language,
            $workDir,
        );

        $wordTimings = $transcription['words'] ?? [];

        // 7. Assemble canonical audio_timeline.json structure
        $timeline = [
            'version' => 1,
            'title' => (string) ($input['title'] ?? 'AI-PVG Production Video'),
            'language' => $language,
            'total_duration' => $totalVideoDuration,
            'tts_provider' => $provider,
            'voice' => $selectedVoice,
            'narration' => $timelineNarration,
            'music' => $musicTimeline,
            'sfx' => $timelineSfx,
            'scenes' => $timelineScenes,
            'mix' => [
                'path' => $mixResult['mix'],
                'duration' => $mixResult['duration'],
                'integrated_lufs' => $mixResult['loudness']['integrated_lufs'] ?? -14.0,
                'true_peak_dbtp' => $mixResult['loudness']['true_peak_dbtp'] ?? -1.5,
                'lra' => $mixResult['loudness']['lra'] ?? 11.0,
            ],
            'word_timings' => $wordTimings,
            'transcription_ok' => $transcription['ok'] ?? false,
        ];

        // 8. Hard-fail validate against schema rules
        $this->validator->assertValid($timeline);

        // 9. Persist audio_timeline.json
        File::put($workDir.'/audio_timeline.json', json_encode($timeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $timeline;
    }
}
