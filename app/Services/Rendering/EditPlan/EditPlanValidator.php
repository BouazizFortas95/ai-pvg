<?php

namespace App\Services\Rendering\EditPlan;

use App\Exceptions\Rendering\InvalidEditPlanException;

/**
 * Deterministic validator for the edit decision list (edit_plan.json).
 *
 * The validator is intentionally stricter than the builder: an invalid plan is
 * rejected before any HTML, TTS, or render work starts.
 */
class EditPlanValidator
{
    public const PRESETS = [
        '9:16' => [1080, 1920],
        '16:9' => [1920, 1080],
        '1:1' => [1080, 1080],
    ];

    public const CAMERA_MOVES = [
        'push-in', 'push-out', 'pan-left', 'pan-right', 'drift-up', 'parallax',
        'push_in', 'push_out', 'pan_left', 'pan_right', 'drift_up',
        'slow-pan-left', 'slow_pan_left', 'whip-pan', 'whip_pan', 'static-macro', 'static_macro',
    ];

    public const CAPTION_STYLES = [
        'word-pop', 'word-highlight', 'line-reveal',
        'bold-snap', 'bold_snap', 'karaoke-pill', 'karaoke_pill', 'editorial-serif', 'editorial_serif',
    ];

    public const PURPOSE = ['hook', 'context', 'evidence', 'payoff', 'cta'];

    public const BEAT_KINDS = ['reveal', 'camera', 'caption', 'transition', 'data', 'payoff'];

    /**
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $plan): array
    {
        $errors = [];

        $this->requireKeys($plan, [
            'version', 'title', 'language', 'preset', 'width', 'height', 'fps',
            'duration', 'theme', 'transition_family', 'audio', 'scenes',
            'beats', 'ending',
        ], 'plan', $errors);

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        if ((int) $plan['version'] !== 1) {
            $errors[] = 'plan.version must be 1';
        }

        $preset = (string) $plan['preset'];

        if (! isset(self::PRESETS[$preset])) {
            $errors[] = 'plan.preset must be one of 9:16, 16:9, 1:1';
        } else {
            [$width, $height] = self::PRESETS[$preset];

            if ((int) $plan['width'] !== $width || (int) $plan['height'] !== $height) {
                $errors[] = "plan dimensions {$plan['width']}x{$plan['height']} do not match preset {$preset}";
            }
        }

        $fps = (int) $plan['fps'];

        if ($fps < 1 || $fps > 120) {
            $errors[] = 'plan.fps must be between 1 and 120';
        }

        $scenes = is_array($plan['scenes'] ?? null) ? $plan['scenes'] : [];
        $sceneCount = count($scenes);

        if ($sceneCount < 3 || $sceneCount > 12) {
            $errors[] = 'plan.scenes must contain 3-12 scenes';
        }

        $this->validateAudio($plan['audio'] ?? null, $errors);
        $this->validateEnding($plan['ending'] ?? null, $errors);

        $duration = (float) $plan['duration'];
        $family = (string) $plan['transition_family'];
        $cursor = 0.0;
        $sceneDurations = [];
        $ids = [];

        foreach ($scenes as $index => $scene) {
            $label = 'scenes['.$index.']';

            if (! is_array($scene)) {
                $errors[] = $label.' must be an object';

                continue;
            }

            $this->requireKeys($scene, [
                'id', 'purpose', 'start', 'duration', 'narration', 'narration_start',
                'headline', 'headline_words', 'visual', 'camera', 'transition_in',
                'caption_style', 'sfx', 'music_intensity', 'beats',
            ], $label, $errors);

            if ($errors !== [] && (isset($scene['start']) && ! is_numeric($scene['start']))) {
                continue;
            }

            $id = (string) ($scene['id'] ?? '');

            if ($id === '' || isset($ids[$id])) {
                $errors[] = $label.'.id must be unique and non-empty';
            }
            $ids[$id] = true;

            $start = (float) ($scene['start'] ?? -1);
            $sceneDuration = (float) ($scene['duration'] ?? 0);

            if (abs($start - $cursor) > 0.051) {
                $errors[] = $label.'.start must be '.$this->fmt($cursor).' (contiguous scenes), got '.$this->fmt($start);
            }

            if ($sceneDuration < 2.0 || $sceneDuration > 4.5) {
                $errors[] = $label.'.duration must be between 2 and 4.5 seconds';
            }

            $sceneDurations[] = $sceneDuration;
            $cursor = round($cursor + $sceneDuration, 3);

            $purpose = (string) ($scene['purpose'] ?? '');

            if (! in_array($purpose, self::PURPOSE, true)) {
                $errors[] = $label.'.purpose must be one of '.implode(', ', self::PURPOSE);
            }

            if ($index === 0 && $purpose !== 'hook') {
                $errors[] = 'the first scene must have purpose "hook"';
            }

            $narration = trim((string) ($scene['narration'] ?? ''));

            if ($narration === '') {
                $errors[] = $label.'.narration must not be empty';
            }

            $narrationStart = (float) ($scene['narration_start'] ?? $start);

            if ($narrationStart < 0 || $narrationStart > $start + 0.001) {
                $errors[] = $label.'.narration_start must be between 0 and the scene start';
            }

            if ($index > 0 && $start > 0) {
                $lead = round($start - $narrationStart, 3);

                if ($lead > 0.001 && ($lead < 0.15 || $lead > 0.3)) {
                    $errors[] = $label.' J-cut lead must be 0.15-0.3s, got '.$this->fmt($lead);
                }
            }

            $headline = trim((string) ($scene['headline'] ?? ''));

            if ($headline === '') {
                $errors[] = $label.'.headline must not be empty';
            }

            $words = is_array($scene['headline_words'] ?? null) ? $scene['headline_words'] : [];

            if ($words === [] || count($words) > 8) {
                $errors[] = $label.'.headline_words must contain 1-8 words';
            }

            foreach ($words as $word) {
                if (! is_array($word) || trim((string) ($word['text'] ?? '')) === '') {
                    $errors[] = $label.'.headline_words entries need non-empty text';
                    break;
                }
            }

            $visual = is_array($scene['visual'] ?? null) ? $scene['visual'] : [];
            $visualKind = (string) ($visual['kind'] ?? '');

            if (! in_array($visualKind, ['asset', 'generated'], true)) {
                $errors[] = $label.'.visual.kind must be asset or generated';
            }

            if ($visualKind === 'asset' && trim((string) ($visual['path'] ?? '')) === '') {
                $errors[] = $label.'.visual.path is required for asset visuals';
            }

            if ($index === 0 && str_contains(strtolower($headline), 'logo')) {
                $errors[] = 'the hook may not open on a logo';
            }

            $camera = is_array($scene['camera'] ?? null) ? $scene['camera'] : [];
            $move = (string) ($camera['type'] ?? '');

            if (! in_array($move, self::CAMERA_MOVES, true)) {
                $errors[] = $label.'.camera.type must be one of '.implode(', ', self::CAMERA_MOVES);
            }

            $intensity = (float) ($camera['intensity'] ?? 1.0);

            if ($intensity < 0.2 || $intensity > 2.0) {
                $errors[] = $label.'.camera.intensity must be between 0.2 and 2.0';
            }

            $transition = is_array($scene['transition_in'] ?? null) ? $scene['transition_in'] : [];

            if ($index > 0) {
                $tFamily = (string) ($transition['family'] ?? '');
                $tType = (string) ($transition['type'] ?? '');
                $tDuration = (float) ($transition['duration'] ?? 0);

                if ($tType === '' || $tFamily === '') {
                    $errors[] = $label.'.transition_in is required on every scene after the first (no jump cuts)';
                } elseif ($tFamily !== $family) {
                    $errors[] = $label.'.transition_in.family must match plan.transition_family ('.$family.'), got '.$tFamily;
                }

                if ($tDuration < 0.2 || $tDuration > 0.8) {
                    $errors[] = $label.'.transition_in.duration must be between 0.2 and 0.8 seconds';
                }
            }

            if (! in_array((string) ($scene['caption_style'] ?? ''), self::CAPTION_STYLES, true)) {
                $errors[] = $label.'.caption_style must be one of '.implode(', ', self::CAPTION_STYLES);
            }

            $intensity = (float) ($scene['music_intensity'] ?? 0);

            if ($intensity < 0.1 || $intensity > 0.6) {
                $errors[] = $label.'.music_intensity must be between 0.1 and 0.6';
            }

            $sceneBeats = is_array($scene['beats'] ?? null) ? $scene['beats'] : [];

            if ($sceneBeats === []) {
                $errors[] = $label.'.beats must not be empty';
            }

            foreach ($sceneBeats as $beat) {
                if (! is_array($beat) || ! isset($beat['time'])) {
                    $errors[] = $label.'.beats entries need a time';
                    break;
                }

                $beatTime = (float) $beat['time'];

                if ($beatTime < $start - 0.001 || $beatTime > $start + $sceneDuration + 0.001) {
                    $errors[] = $label.'.beats must fall inside the scene';
                    break;
                }

                if (! in_array((string) ($beat['kind'] ?? ''), self::BEAT_KINDS, true)) {
                    $errors[] = $label.'.beats.kind must be one of '.implode(', ', self::BEAT_KINDS);
                    break;
                }
            }

            if (isset($scene['data_points']) && is_array($scene['data_points'])) {
                foreach ($scene['data_points'] as $point) {
                    if (! is_array($point) || trim((string) ($point['source'] ?? '')) === '') {
                        $errors[] = $label.'.data_points require a grounded source';
                        break;
                    }
                }
            }
        }

        if (abs($cursor - $duration) > 0.051) {
            $errors[] = 'plan.duration '.$this->fmt($duration).' must equal the scene sum '.$this->fmt($cursor);
        }

        if ($sceneDurations !== []) {
            $varied = false;

            foreach ($sceneDurations as $a) {
                foreach ($sceneDurations as $b) {
                    if (abs($a - $b) > 0.2) {
                        $varied = true;
                    }
                }
            }

            if (! $varied) {
                $errors[] = 'vary shot length; all scene durations are effectively identical';
            }
        }

        $this->validateBeats($plan['beats'] ?? null, $duration, $errors);
        $this->validateJcuts($scenes, $errors);

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    public function assertValid(array $plan): array
    {
        $result = $this->validate($plan);

        if (! $result['ok']) {
            throw new InvalidEditPlanException($result['errors']);
        }

        return $plan;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateAudio(mixed $audio, array &$errors): void
    {
        if (! is_array($audio)) {
            $errors[] = 'plan.audio must be an object';

            return;
        }

        foreach (['voice', 'music', 'target_lufs', 'true_peak', 'fade_out'] as $key) {
            if (! array_key_exists($key, $audio)) {
                $errors[] = 'plan.audio.'.$key.' is required';
            }
        }

        if (isset($audio['target_lufs']) && abs((float) $audio['target_lufs'] + 14) > 0.01) {
            $errors[] = 'plan.audio.target_lufs must be -14 for social delivery';
        }

        if (isset($audio['true_peak']) && (float) $audio['true_peak'] > -1.0) {
            $errors[] = 'plan.audio.true_peak must be at or below -1 dBTP';
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateEnding(mixed $ending, array &$errors): void
    {
        if (! is_array($ending)) {
            $errors[] = 'plan.ending must be an object';

            return;
        }

        foreach (['fade_out_video', 'fade_out_audio', 'payoff'] as $key) {
            if (! array_key_exists($key, $ending)) {
                $errors[] = 'plan.ending.'.$key.' is required';
            }
        }

        if (isset($ending['fade_out_video']) && (float) $ending['fade_out_video'] < 0.5) {
            $errors[] = 'plan.ending.fade_out_video must be at least 0.5s';
        }

        if (isset($ending['fade_out_audio']) && (float) $ending['fade_out_audio'] < 0.5) {
            $errors[] = 'plan.ending.fade_out_audio must be at least 0.5s';
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateBeats(mixed $beats, float $duration, array &$errors): void
    {
        if (! is_array($beats) || count($beats) < 2) {
            $errors[] = 'plan.beats must contain at least two visual beats';

            return;
        }

        $times = array_map(static fn ($beat): float => is_array($beat) ? (float) ($beat['time'] ?? -1) : -1, $beats);
        sort($times);

        if ($times[0] < 0 || $times[0] > 1.5) {
            $errors[] = 'the hook must land within the first 1.5 seconds';
        }

        for ($i = 1; $i < count($times); $i++) {
            $gap = round($times[$i] - $times[$i - 1], 3);

            if ($gap < 1.999 || $gap > 4.001) {
                $errors[] = 'visual beats must land every 2-4 seconds; gap '.$this->fmt($gap).' at '.$this->fmt($times[$i - 1]);
            }
        }

        $last = end($times);

        if ($last < $duration - 4.001) {
            $errors[] = 'the last visual beat is too far from the ending';
        }
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $errors
     */
    private function validateJcuts(array $scenes, array &$errors): void
    {
        $cuts = 0;
        $jcuts = 0;

        foreach ($scenes as $index => $scene) {
            if ($index === 0 || ! is_array($scene)) {
                continue;
            }

            $cuts++;
            $start = (float) ($scene['start'] ?? 0);
            $narrationStart = (float) ($scene['narration_start'] ?? $start);

            if ($narrationStart < $start - 0.001) {
                $jcuts++;
            }
        }

        if ($cuts === 0) {
            return;
        }

        $ratio = $jcuts / $cuts;

        if ($ratio < 0.2 || $ratio > 0.45) {
            $errors[] = 'audio must lead picture on about 30% of cuts (J/L cuts); got '.round($ratio * 100).'%';
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $keys
     * @param  list<string>  $errors
     */
    private function requireKeys(array $value, array $keys, string $label, array &$errors): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $value)) {
                $errors[] = $label.'.'.$key.' is required';
            }
        }
    }

    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
