<?php

namespace App\Services\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use App\Services\Audio\ElevenLabs\MusicService;
use App\Services\Audio\ElevenLabs\SoundEffectService;
use App\Services\Audio\ElevenLabs\TtsService;
use App\Services\Rendering\Audio\AudioProbe;
use App\Services\Rendering\Audio\AudioTimelineValidator;
use App\Services\Rendering\Audio\TranscriptionService;
use App\Services\Rendering\Audio\TtsGenerator;
use Illuminate\Support\Facades\File;

/**
 * Builds the canonical single-source-of-truth audio_timeline.json manifest
 * using the global SFX/Music library caches and broadcast loudness gating.
 */
class AudioTimelineBuilder
{
    public function __construct(
        protected TtsGenerator $tts = new TtsGenerator,
        protected ?TtsService $elevenLabsTts = null,
        protected ?SoundEffectService $sfxService = null,
        protected ?MusicService $musicService = null,
        protected ?MixService $mixService = null,
        protected TranscriptionService $transcriber = new TranscriptionService,
        protected AudioProbe $probe = new AudioProbe,
        protected AudioTimelineValidator $validator = new AudioTimelineValidator,
    ) {
        $this->elevenLabsTts = $elevenLabsTts ?? app(TtsService::class);
        $this->sfxService = $sfxService ?? app(SoundEffectService::class);
        $this->musicService = $musicService ?? app(MusicService::class);
        $this->mixService = $mixService ?? app(MixService::class);
    }

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
        $allWordTimings = [];

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

            // Check if ElevenLabs TTS is active
            if (config('automation.audio.provider') === 'elevenlabs' && (bool) config('automation.audio.elevenlabs.enabled', false)) {
                $result = $this->elevenLabsTts->synthesize($text, $path, [
                    'voice_id' => $voice,
                    'language' => $language,
                ]);
                $provider = 'elevenlabs';
                $selectedVoice = $result['voice'];
                $measuredDuration = $result['duration'];
            } else {
                $ttsResult = $this->tts->synthesize($text, $language, $voice, $path);
                $provider ??= $ttsResult['provider'];
                $selectedVoice ??= $ttsResult['voice'];
                $measuredDuration = round($this->probe->duration($path), 3);
            }

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
        $gap = 0.22;
        $outroPad = 1.0;

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

            $isLast = $index === count($narrationSegments) - 1;
            $sceneDuration = $isLast ? round($duration + $outroPad, 3) : round($duration + $gap, 3);

            // J-cut: audio leads picture by 0.2s on ~30% of cuts
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

        // 3. Music bed: generated or retrieved from global music cache
        $mood = (string) ($input['theme'] ?? 'cyber');
        $musicResult = $this->musicService->generate($mood, $totalVideoDuration, [
            'preset' => $input['preset'] ?? '9:16',
            'theme' => $mood,
        ]);

        $musicFile = $musicResult['path'];
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

        // 4. SFX: tie to scene transition/reveal points, retrieved from global SFX cache
        $sfxCues = [];
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

        // Generate/retrieve SFX files from library cache
        $sfxFiles = [];
        foreach (array_values(array_unique(array_column($sfxCues, 'cue'))) as $cueName) {
            $sfxResult = $this->sfxService->generate($cueName);
            $sfxFiles[$cueName] = $sfxResult['path'];
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

        // 5. Pre-mix with FFmpeg into ONE mix.wav with sidechain ducking and loudness gating
        $mixResult = $this->mixService->mix(
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

        // 6. Transcribe narration for word timings
        $transcription = $this->transcriber->transcribe(
            $mixResult['narration'],
            $language,
            $workDir,
        );

        $wordTimings = $transcription['words'] ?? [];

        // 7. Assemble canonical audio_timeline.json
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

        // 8. Validate against schema rules
        $this->validator->assertValid($timeline);

        // 9. Persist audio_timeline.json
        File::put($workDir.'/audio_timeline.json', json_encode($timeline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $timeline;
    }
}

