<?php

namespace App\Services\Rendering\Audio;

use App\Exceptions\Rendering\AudioTimelineValidationException;

class AudioTimelineValidator
{
    /**
     * Required root keys for audio_timeline.json
     */
    public const REQUIRED_KEYS = [
        'version',
        'total_duration',
        'narration',
        'music',
        'sfx',
        'scenes',
        'mix',
    ];

    /**
     * Validate an audio timeline structure.
     *
     * @param  array<string, mixed>  $timeline
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $timeline): array
    {
        $errors = [];

        // 1. Root structure check
        foreach (self::REQUIRED_KEYS as $key) {
            if (! array_key_exists($key, $timeline)) {
                $errors[] = "Missing required root key: '{$key}'";
            }
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $totalDuration = (float) ($timeline['total_duration'] ?? 0);
        if ($totalDuration <= 0.5) {
            $errors[] = "total_duration must be greater than 0.5s, got {$totalDuration}s";
        }

        // 2. Narration segments check
        $narration = is_array($timeline['narration'] ?? null) ? $timeline['narration'] : [];
        if (empty($narration)) {
            $errors[] = 'The timeline must contain at least one narration segment.';
        }

        $prevEnd = null;
        foreach ($narration as $idx => $seg) {
            $sceneId = $seg['scene_id'] ?? "segment-{$idx}";
            $path = (string) ($seg['path'] ?? '');
            $start = (float) ($seg['start'] ?? 0);
            $end = (float) ($seg['end'] ?? 0);
            $duration = (float) ($seg['duration'] ?? 0);

            // File existence & non-empty
            if ($path === '' || ! is_file($path)) {
                $errors[] = "Narration {$sceneId} file does not exist: {$path}";
            } elseif (filesize($path) <= 0) {
                $errors[] = "Narration {$sceneId} file is empty: {$path}";
            }

            // Duration consistency
            if ($duration <= 0.05) {
                $errors[] = "Narration {$sceneId} duration must be > 0.05s, got {$duration}s";
            }
            if (abs(($end - $start) - $duration) > 0.02) {
                $errors[] = "Narration {$sceneId} timing mismatch: end ({$end}) - start ({$start}) != duration ({$duration})";
            }

            // Rule: No clip starts at 0 except the opening narration or music
            if ($idx > 0 && $start <= 0.01) {
                $errors[] = "Narration segment #{$idx} ({$sceneId}) starts at 0.0s; only opening narration may start at 0.";
            }

            // Rule: No two narration segments overlap
            if ($prevEnd !== null) {
                if ($start < $prevEnd - 0.01) {
                    $errors[] = "Narration overlap detected: segment #{$idx} starts at {$start}s before previous ended at {$prevEnd}s";
                }

                // Rule: No unexplained silence longer than 0.7s between narration lines
                $gap = $start - $prevEnd;
                if ($gap > 0.70) {
                    $errors[] = "Unexplained silence of {$gap}s (> 0.7s target) between narration segments #".($idx - 1)." and #{$idx}";
                }
            }

            $prevEnd = $end;
        }

        // 3. Music coverage check
        $music = is_array($timeline['music'] ?? null) ? $timeline['music'] : [];
        if (! empty($music)) {
            $musicPath = (string) ($music['path'] ?? '');
            $musicStart = (float) ($music['start'] ?? 0);
            $musicEnd = (float) ($music['end'] ?? 0);

            if ($musicPath !== '' && (! is_file($musicPath) || filesize($musicPath) <= 0)) {
                $errors[] = "Music file missing or empty: {$musicPath}";
            }

            if ($musicStart > 0.05) {
                $errors[] = "Music bed must start at 0.0s (covers whole video), got start={$musicStart}s";
            }

            // Music must cover the whole video
            if ($musicEnd < $totalDuration - 0.05) {
                $errors[] = "Music bed ({$musicEnd}s) is shorter than video duration ({$totalDuration}s); cuts out early!";
            }
        }

        // 4. Scenes check: scene durations must be derived from narration + padding
        $scenes = is_array($timeline['scenes'] ?? null) ? $timeline['scenes'] : [];
        $sumSceneDurations = 0.0;

        foreach ($scenes as $scene) {
            $sceneDur = (float) ($scene['duration'] ?? 0);
            $sumSceneDurations += $sceneDur;
        }

        // Rule: Sum of scene durations matches total_duration within 50 ms
        if (abs($sumSceneDurations - $totalDuration) > 0.05) {
            $diff = round($sumSceneDurations - $totalDuration, 3);
            $errors[] = "Sum of scene durations ({$sumSceneDurations}s) does not match total video duration ({$totalDuration}s) by {$diff}s (> 50ms tolerance)";
        }

        // 5. Mix file check
        $mix = is_array($timeline['mix'] ?? null) ? $timeline['mix'] : [];
        $mixPath = (string) ($mix['path'] ?? '');
        if ($mixPath !== '') {
            if (! is_file($mixPath)) {
                $errors[] = "Pre-mixed delivery file missing on disk: {$mixPath}";
            } elseif (filesize($mixPath) < 100) {
                $errors[] = "Pre-mixed delivery file is empty or corrupted: {$mixPath}";
            }
        }

        return [
            'ok' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate and throw if invalid.
     *
     * @param  array<string, mixed>  $timeline
     * @return array<string, mixed>
     *
     * @throws AudioTimelineValidationException
     */
    public function assertValid(array $timeline): array
    {
        $result = $this->validate($timeline);

        if (! $result['ok']) {
            throw new AudioTimelineValidationException($result['errors']);
        }

        return $timeline;
    }
}
