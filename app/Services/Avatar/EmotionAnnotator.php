<?php

namespace App\Services\Avatar;

/**
 * EmotionAnnotator parses narration sentences, annotates emotional beats,
 * enforces ToneGuard boundaries, rate-limits expression changes (max 3/sec),
 * and guarantees gestures return to the rest pose.
 */
final class EmotionAnnotator
{
    public const EXPRESSIONS = [
        'neutral',
        'serious',
        'confident',
        'thoughtful',
        'subtle_smile',
    ];

    public const GESTURES = [
        'rest',
        'subtle_nod',
        'hand_emphasize',
        'thoughtful_head_tilt',
    ];

    /**
     * Annotate an array of audio manifest narration scenes with expressions and gestures.
     *
     * @param  array<int, array{scene_id: string, start: float, duration: float, text: string}>  $scenes
     * @return array<int, array<string, mixed>>
     */
    public function annotateScenes(array $scenes): array
    {
        $timeline = [];
        $lastExpressionTime = -999.0;
        $currentExpression = 'neutral';

        foreach ($scenes as $scene) {
            $text = trim((string) ($scene['text'] ?? ''));
            $start = (float) ($scene['start'] ?? 0.0);
            $duration = (float) ($scene['duration'] ?? 4.0);
            $end = $start + $duration;

            // 1. Determine raw candidate beat from text
            $candidate = $this->determineCandidateBeat($text);

            // 2. Guard via ToneGuard
            $guarded = ToneGuard::guard($text, $candidate);
            $targetExpression = $guarded['expression'];
            $targetGesture = $guarded['gesture'];

            // 3. Rate-limit expression changes (max 3 per second -> interval >= 0.33s)
            if ($targetExpression !== $currentExpression) {
                if (($start - $lastExpressionTime) < 0.33) {
                    $targetExpression = $currentExpression;
                } else {
                    $currentExpression = $targetExpression;
                    $lastExpressionTime = $start;
                }
            }

            // 4. Calculate expression transforms
            $expressionOffsets = self::expressionToOffsets($targetExpression, $guarded['intensity']);

            // 5. Build gesture lifecycle with guaranteed return-to-rest
            $gestureDuration = min(1.4, max(0.6, $duration * 0.4));
            $gestureReturnTime = min($end - 0.2, $start + $gestureDuration);

            $beatRecord = [
                'scene_id' => $scene['scene_id'] ?? 'scene',
                'start' => round($start, 3),
                'end' => round($end, 3),
                'text' => $text,
                'expression' => $targetExpression,
                'gesture' => $targetGesture,
                'gesture_return_time' => round($gestureReturnTime, 3),
                'intensity' => $guarded['intensity'],
                'sensitivity' => $guarded['sensitivity'],
                'guarded' => $guarded['guarded'],
                'intervention_reason' => $guarded['intervention_reason'],
                'offsets' => $expressionOffsets,
            ];

            $timeline[] = $beatRecord;
        }

        return $timeline;
    }

    /**
     * Map expression type to SVG brow/eye/mouth offsets.
     *
     * @return array{brow_y: float, brow_tilt: float, eye_scale: float, mouth_curve: float}
     */
    public static function expressionToOffsets(string $expression, float $intensity = 0.5): array
    {
        return match ($expression) {
            'serious' => [
                'brow_y' => round(3.5 * $intensity, 1),
                'brow_tilt' => round(7.0 * $intensity, 1), // inwards angled
                'eye_scale' => round(1.0 - (0.08 * $intensity), 2),
                'mouth_curve' => round(-2.5 * $intensity, 1), // subtle flat/stern
            ],
            'thoughtful' => [
                'brow_y' => round(-2.5 * $intensity, 1),
                'brow_tilt' => round(-4.0 * $intensity, 1), // one brow up
                'eye_scale' => 0.96,
                'mouth_curve' => -0.5,
            ],
            'confident' => [
                'brow_y' => round(-3.0 * $intensity, 1),
                'brow_tilt' => 0.0,
                'eye_scale' => 1.02,
                'mouth_curve' => round(2.0 * $intensity, 1),
            ],
            'subtle_smile' => [
                'brow_y' => round(-2.0 * $intensity, 1),
                'brow_tilt' => round(-2.0 * $intensity, 1),
                'eye_scale' => 0.98,
                'mouth_curve' => round(3.5 * $intensity, 1),
            ],
            default => [ // neutral
                'brow_y' => 0.0,
                'brow_tilt' => 0.0,
                'eye_scale' => 1.0,
                'mouth_curve' => 0.0,
            ],
        };
    }

    /**
     * Determine candidate beat from text cues.
     *
     * @return array{expression: string, gesture: string, intensity: float}
     */
    protected function determineCandidateBeat(string $text): array
    {
        $normalized = strtolower($text);

        if (str_contains($normalized, 'breaking') || str_contains($normalized, 'exclusive') || str_contains($normalized, '!')) {
            return [
                'expression' => 'confident',
                'gesture' => 'hand_emphasize',
                'intensity' => 0.85,
            ];
        }

        if (str_contains($normalized, 'why') || str_contains($normalized, 'future') || str_contains($normalized, 'whether') || str_contains($normalized, '?')) {
            return [
                'expression' => 'thoughtful',
                'gesture' => 'thoughtful_head_tilt',
                'intensity' => 0.70,
            ];
        }

        if (str_contains($normalized, 'record') || str_contains($normalized, 'launches') || str_contains($normalized, 'flagship') || str_contains($normalized, 'benchmark')) {
            return [
                'expression' => 'confident',
                'gesture' => 'subtle_nod',
                'intensity' => 0.75,
            ];
        }

        if (str_contains($normalized, 'court') || str_contains($normalized, 'lawsuit') || str_contains($normalized, 'regulation') || str_contains($normalized, 'investigation')) {
            return [
                'expression' => 'serious',
                'gesture' => 'subtle_nod',
                'intensity' => 0.80,
            ];
        }

        return [
            'expression' => 'neutral',
            'gesture' => 'rest',
            'intensity' => 0.50,
        ];
    }
}
