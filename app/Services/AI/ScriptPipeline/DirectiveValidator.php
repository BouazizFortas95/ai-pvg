<?php

namespace App\Services\AI\ScriptPipeline;

use App\Exceptions\Automation\ScriptDirectiveValidationException;

/**
 * Validates the G-03 scene directive schema. Scenes without a `directives`
 * key are legacy G-01/G-02 output and always pass through unchanged.
 */
class DirectiveValidator
{
    /** @var list<string> */
    private const REQUIRED_DIRECTIVES = [
        'camera_motion',
        'b_roll_query',
        'highlight_text',
        'sfx_cue',
        'emotion_tone',
        'duration_seconds',
    ];

    /** @var list<string> */
    private const CAMERA_MOTIONS = ['slow_in', 'slow_out', 'pan_left', 'pan_right', 'push_in', 'static'];

    /** @var list<string> */
    private const SFX_CUES = ['whoosh', 'impact', 'riser', 'none'];

    /** @var list<string> */
    private const EMOTION_TONES = ['urgent', 'curious', 'confident', 'serious', 'calm', 'exciting'];

    /** @var list<string> */
    private const SCENE_TYPES = ['intro', 'segment', 'conclusion', 'outro'];

    /**
     * @param  array<int, mixed>  $scenes
     * @param  list<int>  $validNewsIds
     * @return array<int, mixed>
     */
    public function validate(array $scenes, int $targetDuration, array $validNewsIds = []): array
    {
        $withDirectives = 0;

        foreach ($scenes as $scene) {
            if (is_array($scene) && array_key_exists('directives', $scene)) {
                $withDirectives++;
            }
        }

        // Backward compatibility: legacy scenes have no directives at all.
        if ($withDirectives === 0) {
            return $scenes;
        }

        $errors = [];
        $totalDuration = 0;
        $valid = array_values(array_unique(array_map('intval', $validNewsIds)));
        $tolerance = max(0, (int) config('automation.script_agents.duration_tolerance', 5));
        $maxScene = max(1, (int) config('automation.script_agents.max_scene_seconds', 12));

        if ($withDirectives !== count($scenes)) {
            $errors[] = 'Directives must be present on every scene or on none.';
        }

        foreach (array_values($scenes) as $index => $scene) {
            $position = $index + 1;

            if (! is_array($scene)) {
                $errors[] = "Scene {$position} must be an object.";

                continue;
            }

            $order = $scene['order'] ?? null;

            if (! is_int($order) || $order !== $position) {
                $errors[] = "Scene {$position} must have contiguous order {$position}.";
            }

            $type = $scene['type'] ?? null;

            if (! is_string($type) || ! in_array($type, self::SCENE_TYPES, true)) {
                $errors[] = "Scene {$position} has an invalid type.";
            }

            $duration = $scene['duration'] ?? null;

            if (! is_int($duration) || $duration < 1 || $duration > $maxScene) {
                $errors[] = "Scene {$position} duration must be between 1 and {$maxScene} seconds.";
                $duration = 0;
            }

            $totalDuration += (int) $duration;

            if (! isset($scene['narration']) || ! is_string($scene['narration']) || trim($scene['narration']) === '') {
                $errors[] = "Scene {$position} narration cannot be empty.";
            }

            $sourceIds = $scene['source_news_ids'] ?? null;

            if (! is_array($sourceIds) || $sourceIds === []) {
                $errors[] = "Scene {$position} requires at least one source news id.";
            } else {
                $ids = array_map('intval', $sourceIds);

                if ($valid !== [] && array_diff($ids, $valid) !== []) {
                    $errors[] = "Scene {$position} references source ids outside the grounded cluster.";
                }
            }

            if (! array_key_exists('directives', $scene)) {
                $errors[] = "Scene {$position} is missing directives.";

                continue;
            }

            $directives = $scene['directives'];

            if (! is_array($directives)) {
                $errors[] = "Scene {$position} directives must be an object.";

                continue;
            }

            $missing = array_values(array_diff(self::REQUIRED_DIRECTIVES, array_keys($directives)));
            $unknown = array_values(array_diff(array_keys($directives), self::REQUIRED_DIRECTIVES));

            if ($missing !== []) {
                $errors[] = "Scene {$position} directives missing: ".implode(', ', $missing).'.';
            }

            if ($unknown !== []) {
                $errors[] = "Scene {$position} directives contain unsupported keys: ".implode(', ', $unknown).'.';
            }

            $camera = $directives['camera_motion'] ?? null;

            if (! is_string($camera) || ! in_array($camera, self::CAMERA_MOTIONS, true)) {
                $errors[] = "Scene {$position} camera_motion is invalid.";
            }

            $bRoll = $directives['b_roll_query'] ?? null;

            if (! is_string($bRoll) || trim($bRoll) === '' || mb_strlen($bRoll) > 120) {
                $errors[] = "Scene {$position} b_roll_query must be a non-empty string under 120 characters.";
            }

            $highlight = $directives['highlight_text'] ?? null;

            if (! is_string($highlight) || trim($highlight) === '' || mb_strlen($highlight) > 80) {
                $errors[] = "Scene {$position} highlight_text must be a non-empty string under 80 characters.";
            }

            $sfx = $directives['sfx_cue'] ?? null;

            if (! is_string($sfx) || ! in_array($sfx, self::SFX_CUES, true)) {
                $errors[] = "Scene {$position} sfx_cue is invalid.";
            }

            $tone = $directives['emotion_tone'] ?? null;

            if (! is_string($tone) || ! in_array($tone, self::EMOTION_TONES, true)) {
                $errors[] = "Scene {$position} emotion_tone is invalid.";
            }

            $directiveDuration = $directives['duration_seconds'] ?? null;

            if (! is_int($directiveDuration) || $directiveDuration < 1) {
                $errors[] = "Scene {$position} duration_seconds must be a positive integer.";
            } elseif ($duration !== 0 && $directiveDuration !== $duration) {
                $errors[] = "Scene {$position} duration_seconds must equal its duration.";
            }
        }

        if ($errors === [] && abs($totalDuration - $targetDuration) > $tolerance) {
            $errors[] = "Total scene duration {$totalDuration}s is outside the {$targetDuration}s target by more than {$tolerance}s.";
        }

        if ($errors !== []) {
            throw ScriptDirectiveValidationException::failed($errors);
        }

        return $scenes;
    }
}
