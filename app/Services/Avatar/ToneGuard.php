<?php

namespace App\Services\Avatar;

/**
 * ToneGuard enforces content sensitivity boundaries.
 *
 * It classifies text into: normal | sensitive | tragic.
 * In sensitive or tragic contexts, it strictly prohibits smiling expressions,
 * playful gestures, and high-energy SFX cues.
 */
final class ToneGuard
{
    public const TRAGIC_KEYWORDS = [
        'death', 'fatal', 'killed', 'casualty', 'disaster', 'tragedy',
        'massacre', 'explosion', 'war', 'famine', 'mourning', 'grief',
        'victim', 'died', 'catastrophe',
    ];

    public const SENSITIVE_KEYWORDS = [
        'layoff', 'breach', 'hack', 'lawsuit', 'fraud', 'investigation',
        'crisis', 'bankruptcy', 'recession', 'scandal', 'crash', 'arrest',
        'indicted', 'fine', 'penalty', 'outage', 'vulnerability', 'allegation',
    ];

    /**
     * Classify content sensitivity level.
     *
     * @return 'normal'|'sensitive'|'tragic'
     */
    public static function classify(string $text): string
    {
        $normalized = strtolower($text);

        foreach (self::TRAGIC_KEYWORDS as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $normalized)) {
                return 'tragic';
            }
        }

        foreach (self::SENSITIVE_KEYWORDS as $word) {
            if (preg_match('/\b'.preg_quote($word, '/').'\b/i', $normalized)) {
                return 'sensitive';
            }
        }

        return 'normal';
    }

    /**
     * Guard an emotional beat according to content sensitivity.
     *
     * @param  array{expression: string, gesture: string, intensity: float, sfx_cue?: string}  $beat
     * @return array{expression: string, gesture: string, intensity: float, sfx_cue: string, sensitivity: string, guarded: bool, intervention_reason: ?string}
     */
    public static function guard(string $text, array $beat): array
    {
        $sensitivity = self::classify($text);
        $expression = (string) ($beat['expression'] ?? 'neutral');
        $gesture = (string) ($beat['gesture'] ?? 'rest');
        $intensity = (float) ($beat['intensity'] ?? 0.5);
        $sfxCue = (string) ($beat['sfx_cue'] ?? 'none');

        $guarded = false;
        $interventions = [];

        if ($sensitivity === 'tragic') {
            // Strictly ban smiling, playful head tilts, enthusiastic gestures, high energy stings
            if ($expression === 'subtle_smile' || $expression === 'confident') {
                $expression = 'serious';
                $guarded = true;
                $interventions[] = "Expression forced to 'serious' (banned smiling/confident for tragic tone)";
            }

            if (! in_array($gesture, ['rest', 'subtle_nod'], true)) {
                $gesture = 'rest';
                $guarded = true;
                $interventions[] = "Gesture reset to 'rest' for tragic tone";
            }

            if (in_array($sfxCue, ['snap', 'impact', 'whoosh'], true)) {
                $sfxCue = 'sub_drop';
                $guarded = true;
                $interventions[] = "SFX cue dampened to 'sub_drop'";
            }

            $intensity = min(0.6, $intensity);
        } elseif ($sensitivity === 'sensitive') {
            // Ban smiling
            if ($expression === 'subtle_smile') {
                $expression = 'serious';
                $guarded = true;
                $interventions[] = "Expression forced to 'serious' (banned smiling for sensitive tone)";
            }

            if (! in_array($gesture, ['rest', 'subtle_nod', 'thoughtful_head_tilt'], true)) {
                $gesture = 'rest';
                $guarded = true;
                $interventions[] = "Gesture tempered to 'rest' for sensitive tone";
            }
        }

        return [
            'expression' => $expression,
            'gesture' => $gesture,
            'intensity' => $intensity,
            'sfx_cue' => $sfxCue,
            'sensitivity' => $sensitivity,
            'guarded' => $guarded,
            'intervention_reason' => $guarded ? implode('; ', $interventions) : null,
        ];
    }
}
