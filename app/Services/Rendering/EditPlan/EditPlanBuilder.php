<?php

namespace App\Services\Rendering\EditPlan;

use App\Models\VideoScript;

/**
 * Deterministic edit decision list builder. No LLM, clock, network or random
 * source is used; the same script/payload + options always produce the same
 * plan.
 */
class EditPlanBuilder
{
    public function __construct(
        protected EditPlanValidator $validator = new EditPlanValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function fromScript(VideoScript $script, array $options = []): array
    {
        $scenes = [];

        foreach (array_values(is_array($script->scenes) ? $script->scenes : []) as $index => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $scenes[] = [
                'order' => (int) ($scene['order'] ?? $index + 1),
                'type' => (string) ($scene['type'] ?? 'segment'),
                'duration' => $scene['duration'] ?? null,
                'headline' => (string) ($scene['headline'] ?? $scene['title'] ?? ''),
                'description' => (string) ($scene['description'] ?? ''),
                'narration' => (string) ($scene['narration'] ?? ''),
                'highlight' => (string) ($scene['directives']['highlight_text'] ?? ''),
            ];
        }

        return $this->build([
            'title' => (string) $script->title,
            'language' => (string) ($script->language ?? 'en'),
            'target_duration' => (float) ($script->target_duration ?? 0),
            'scenes' => $scenes,
        ], $options);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function fromPayload(array $payload, array $options = []): array
    {
        $scenes = [];

        foreach (array_values(is_array($payload['scenes'] ?? null) ? $payload['scenes'] : []) as $index => $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $scenes[] = [
                'order' => (int) ($scene['order'] ?? $index + 1),
                'type' => (string) ($scene['type'] ?? 'segment'),
                'duration' => $scene['duration'] ?? null,
                'headline' => (string) ($scene['headline'] ?? $scene['title'] ?? ''),
                'description' => (string) ($scene['description'] ?? ''),
                'narration' => (string) ($scene['narration'] ?? ''),
                'highlight' => (string) ($scene['directives']['highlight_text'] ?? ''),
                'visual' => is_array($scene['visual'] ?? null) ? $scene['visual'] : null,
            ];
        }

        return $this->build([
            'title' => (string) ($payload['title'] ?? 'AI-PVG'),
            'language' => (string) ($payload['language'] ?? 'en'),
            'target_duration' => (float) ($payload['target_duration'] ?? 0),
            'scenes' => $scenes,
        ], $options);
    }

    /**
     * @param  array{title: string, language: string, target_duration: float, scenes: list<array<string, mixed>>}  $input
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function build(array $input, array $options): array
    {
        $preset = (string) ($options['preset'] ?? '9:16');
        $dimensions = EditPlanValidator::PRESETS[$preset] ?? EditPlanValidator::PRESETS['9:16'];
        [$width, $height] = $dimensions;
        $fps = max(1, (int) ($options['fps'] ?? config('automation.render.hyperframes.fps', 30)));
        $language = (string) ($options['language'] ?? $input['language'] ?? 'en');
        $themeName = (string) ($options['theme'] ?? 'cyber');
        $assetMap = is_array($options['assets'] ?? null) ? $options['assets'] : [];

        $scenes = $input['scenes'];
        $sceneCount = max(1, count($scenes));
        $durations = [];

        foreach ($scenes as $index => $scene) {
            $durations[$index] = $this->sceneDuration($scene, $input['target_duration'], $sceneCount);
        }

        $planScenes = [];
        $globalBeats = [];
        $cursor = 0.0;

        foreach ($scenes as $index => $scene) {
            $duration = $durations[$index];
            $start = round($cursor, 3);
            $purpose = $this->purpose($index, $sceneCount);
            $narration = trim((string) ($scene['narration'] ?? ''));
            $narration = $narration !== '' ? $narration : trim((string) ($scene['description'] ?? ''));

            if ($narration === '') {
                $narration = (string) ($scene['headline'] ?? $input['title']);
            }

            $narrationStart = $start;

            // J-cut on roughly one third of the cuts: the next line starts
            // 0.2s before its visual, so audio leads picture.
            if ($index > 0 && $index % 3 === 1) {
                $narrationStart = round(max(0, $start - 0.2), 3);
            }

            $transition = null;

            if ($index > 0) {
                $transition = [
                    'type' => $index % 2 === 0 ? 'blur-wipe-left' : 'blur-wipe-right',
                    'family' => 'blur-wipe',
                    'duration' => 0.42,
                ];
            }

            $beats = [
                ['time' => $start, 'kind' => $index === 0 ? 'reveal' : 'transition'],
            ];

            if ($duration / 2 >= 2.0) {
                $beats[] = ['time' => round($start + $duration / 2, 3), 'kind' => 'camera'];
            }

            foreach ($beats as $beat) {
                $globalBeats[] = $beat;
            }

            // Transition beats are already included as scene-start beats (for index > 0)
            // No need to add separate transition beats

            $visual = $this->visual($index, $scene, $assetMap, $themeName);
            $camera = $this->camera($index);
            $sfx = $this->sfx($index, $sceneCount, $start, $duration, $purpose);

            $planScenes[] = [
                'id' => 'scene-'.($index + 1),
                'purpose' => $purpose,
                'start' => $start,
                'duration' => round($duration, 3),
                'narration' => $narration,
                'narration_start' => $narrationStart,
                'headline' => (string) ($scene['headline'] ?? $input['title']),
                'headline_words' => $this->headlineWords(
                    (string) ($scene['headline'] ?? $input['title']),
                    (string) ($scene['highlight'] ?? ''),
                ),
                'visual' => $visual,
                'camera' => $camera,
                'transition_in' => $transition,
                'caption_style' => 'word-pop',
                'sfx' => $sfx,
                'music_intensity' => $this->musicIntensity($purpose),
                'beats' => $beats,
            ];

            $cursor = round($cursor + $duration, 3);
        }

        usort($globalBeats, static fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        $plan = [
            'version' => 1,
            'title' => $input['title'],
            'language' => $language,
            'preset' => $preset,
            'width' => $width,
            'height' => $height,
            'fps' => $fps,
            'duration' => round($cursor, 3),
            'theme' => $themeName,
            'transition_family' => 'blur-wipe',
            'audio' => [
                'voice' => (string) ($options['voice'] ?? 'auto'),
                'music' => 'local',
                'target_lufs' => (float) config('automation.render.hyperframes.audio.target_lufs', -14),
                'true_peak' => (float) config('automation.render.hyperframes.audio.true_peak', -1.5),
                'fade_out' => 0.5,
            ],
            'scenes' => $planScenes,
            'beats' => $globalBeats,
            'ending' => [
                'payoff' => end($planScenes)['headline'] ?: $input['title'],
                'fade_out_video' => 0.5,
                'fade_out_audio' => 0.5,
            ],
        ];

        return $this->validator->assertValid($plan);
    }

    /**
     * @param  array<string, mixed>  $scene
     */
    private function sceneDuration(array $scene, float $targetDuration, int $sceneCount): float
    {
        $explicit = $scene['duration'] ?? null;

        if (is_numeric($explicit) && (float) $explicit >= 2.0 && (float) $explicit <= 4.5) {
            return (float) $explicit;
        }

        $text = trim((string) ($scene['narration'] ?? ''));

        if ($text === '') {
            $text = trim((string) ($scene['description'] ?? ''));
        }

        $words = max(1, str_word_count($text));

        if ($text === '') {
            return $targetDuration > 0 ? max(2.0, min(4.5, $targetDuration / $sceneCount)) : 3.5;
        }

        return max(2.6, min(4.4, round($words * 0.37 + 0.7, 1)));
    }

    private function purpose(int $index, int $sceneCount): string
    {
        if ($index === 0) {
            return 'hook';
        }

        if ($index === $sceneCount - 1) {
            return 'cta';
        }

        if ($index === $sceneCount - 2) {
            return 'payoff';
        }

        return $index === 1 ? 'context' : 'evidence';
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  array<int, array<string, mixed>>  $assetMap
     * @return array<string, mixed>
     */
    private function visual(int $index, array $scene, array $assetMap, string $themeName): array
    {
        $asset = $assetMap[$index] ?? $assetMap[$index + 1] ?? null;

        if (is_array($asset) && trim((string) ($asset['path'] ?? '')) !== '') {
            return [
                'kind' => 'asset',
                'path' => (string) $asset['path'],
                'attribution' => (string) ($asset['attribution'] ?? ''),
                'palette' => $this->palette($themeName, $index),
            ];
        }

        return [
            'kind' => 'generated',
            'source' => 'generated://scene-'.($index + 1),
            'pattern' => ['orbital', 'grid-tilt', 'scanline', 'prism', 'halo', 'horizon'][$index % 6],
            'palette' => $this->palette($themeName, $index),
        ];
    }

    /**
     * @return array{type: string, intensity: float}
     */
    private function camera(int $index): array
    {
        $moves = ['push-in', 'pan-right', 'push-out', 'pan-left', 'parallax', 'drift-up'];

        return [
            'type' => $moves[$index % count($moves)],
            'intensity' => round(0.8 + (($index % 3) * 0.15), 2),
        ];
    }

    /**
     * @return list<array{cue: string, time: float, gain: float}>
     */
    private function sfx(int $index, int $sceneCount, float $start, float $duration, string $purpose): array
    {
        $cues = [];

        if ($index > 0) {
            $cues[] = ['cue' => 'whoosh', 'time' => $start, 'gain' => 0.5];
        }

        $cues[] = ['cue' => 'hit', 'time' => round($start + 0.28, 3), 'gain' => 0.28];

        if ($purpose === 'payoff') {
            $cues[] = ['cue' => 'riser', 'time' => round(max(0, $start - 0.5), 3), 'gain' => 0.32];
        }

        if ($index === $sceneCount - 1) {
            $cues[] = ['cue' => 'hit', 'time' => round($start + max(0.3, $duration - 0.9), 3), 'gain' => 0.22];
        }

        return $cues;
    }

    /**
     * @return list<array{text: string, emphasis: bool}>
     */
    private function headlineWords(string $headline, string $highlight): array
    {
        $words = preg_split('/\s+/u', trim($headline)) ?: [];
        $highlightWords = array_map(
            static fn (string $word): string => mb_strtolower(trim($word, " \t\n\r\0\x0B.,!?;:")),
            preg_split('/\s+/u', trim($highlight)) ?: [],
        );

        $result = [];
        $index = 0;

        foreach ($words as $word) {
            $clean = trim($word);

            if ($clean === '') {
                continue;
            }

            $normalized = mb_strtolower(trim($clean, " \t\n\r\0\x0B.,!?;:"));
            $isNumber = preg_match('/\d/', $clean) === 1;
            $isEmphasis = $isNumber
                || in_array($normalized, $highlightWords, true)
                || ($index === 0 && count($words) <= 4);

            $result[] = ['text' => $clean, 'emphasis' => $isEmphasis];
            $index++;
        }

        return $result === [] ? [['text' => $headline, 'emphasis' => true]] : $result;
    }

    private function musicIntensity(string $purpose): float
    {
        return match ($purpose) {
            'hook' => 0.34,
            'context' => 0.26,
            'evidence' => 0.22,
            'payoff' => 0.30,
            'cta' => 0.38,
            default => 0.25,
        };
    }

    /**
     * @return list<string>
     */
    private function palette(string $theme, int $index): array
    {
        $palettes = [
            'cyber' => ['#00d4ff', '#7c3aed', '#0b1020'],
            'breaking' => ['#ff3b5c', '#ffb020', '#160a12'],
            'minimal' => ['#f5f5f5', '#8b5cf6', '#111111'],
        ];

        $base = $palettes[$theme] ?? $palettes['cyber'];
        $rotate = $index % 3;

        return [$base[$rotate % 3], $base[($rotate + 1) % 3], $base[($rotate + 2) % 3]];
    }
}
