<?php

namespace App\Services\Avatar;

/**
 * Maps English words/phonemes to the 6-viseme set used by the SVG rig.
 *
 * Visemes:
 *   closed  — silence / M / B / P (lips together)
 *   small   — E / I / EE / short-i (narrow opening)
 *   wide    — A / AH / open-ah (wide open)
 *   round   — O / OO / U (rounded lips)
 *   fv      — F / V (lip-to-teeth)
 *   th      — TH / D / T (tongue near teeth)
 */
final class VisemeMapper
{
    /**
     * Simple word-onset → viseme lookup.
     * Words beginning with these letters map to the listed viseme;
     * the dominant vowel sound governs when onset is not a stop/fricative.
     *
     * @var array<string, string>
     */
    private const WORD_MAP = [
        // Bilabial stops → closed
        'b' => 'closed', 'm' => 'closed', 'p' => 'closed',
        // Labiodental → fv
        'f' => 'fv', 'v' => 'fv',
        // Dental/alveolar → th
        'd' => 'th', 't' => 'th',
        // TH words
        'th' => 'th',
        // Rounded vowel words
        'o' => 'round', 'u' => 'round', 'w' => 'round',
        // Wide / open-ah vowel words
        'a' => 'wide',
        // Small / narrow-vowel words
        'e' => 'small', 'i' => 'small', 'y' => 'small',
    ];

    /**
     * Map a single word to a viseme.
     * Falls back to 'wide' for unknown onsets (open mouth approximates speech).
     */
    public static function wordToViseme(string $word): string
    {
        $word = strtolower(trim(preg_replace('/[^a-z]/i', '', $word) ?? ''));
        if ($word === '') {
            return 'closed';
        }

        // Check two-char onset first (th)
        if (strlen($word) >= 2 && isset(self::WORD_MAP[substr($word, 0, 2)])) {
            return self::WORD_MAP[substr($word, 0, 2)];
        }

        return self::WORD_MAP[$word[0]] ?? 'wide';
    }

    /**
     * Build a per-frame lip-sync track from word timings.
     *
     * @param  array<int, array{word: string, startMs: int, endMs: int}>  $wordTimings
     * @return array<int, array{frame: int, viseme: string, openness: float}>
     */
    public static function buildTrack(array $wordTimings, float $audioDuration, int $fps = 30): array
    {
        $totalFrames = (int) ceil($audioDuration * $fps);
        $track = [];

        // Build frame→{viseme, openness} map; default = closed
        $frameMap = array_fill(0, $totalFrames, ['viseme' => 'closed', 'openness' => 0.0]);

        foreach ($wordTimings as $timing) {
            $viseme = self::wordToViseme($timing['word']);
            $startFrame = (int) floor($timing['startMs'] / 1000 * $fps);
            $endFrame = (int) ceil($timing['endMs'] / 1000 * $fps);
            $endFrame = min($endFrame, $totalFrames - 1);

            // Openness by viseme type
            $openness = match ($viseme) {
                'wide' => 1.0,
                'round' => 0.75,
                'small' => 0.50,
                'fv' => 0.35,
                'th' => 0.30,
                'closed' => 0.0,
                default => 0.6,
            };

            for ($f = $startFrame; $f <= $endFrame; $f++) {
                $frameMap[$f] = ['viseme' => $viseme, 'openness' => $openness];
            }
        }

        foreach ($frameMap as $frame => $entry) {
            $track[] = ['frame' => $frame, 'viseme' => $entry['viseme'], 'openness' => $entry['openness']];
        }

        return $track;
    }
}
