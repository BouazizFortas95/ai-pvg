<?php

namespace App\Services\Audio\ElevenLabs;

class AlignmentConverter
{
    /**
     * Convert ElevenLabs character-level alignment to monotonic word-level timings.
     *
     * @param array{
     *     characters: list<string>,
     *     character_start_times_seconds: list<float>,
     *     character_end_times_seconds: list<float>
     * } $alignment
     * @return list<array{word: string, startMs: int, endMs: int}>
     */
    public function toWordTimings(array $alignment): array
    {
        $chars = $alignment['characters'] ?? [];
        $starts = $alignment['character_start_times_seconds'] ?? [];
        $ends = $alignment['character_end_times_seconds'] ?? [];

        $count = count($chars);
        if ($count === 0 || count($starts) !== $count || count($ends) !== $count) {
            return [];
        }

        $words = [];
        $currentWord = '';
        $wordStartSec = null;
        $wordEndSec = null;

        for ($i = 0; $i < $count; $i++) {
            $char = $chars[$i];
            $charStart = (float) $starts[$i];
            $charEnd = (float) $ends[$i];

            // Space or newline indicates word boundary
            if (ctype_space($char) || $char === ' ' || $char === "\n" || $char === "\t") {
                if ($currentWord !== '') {
                    $words[] = [
                        'word' => $currentWord,
                        'startMs' => (int) round($wordStartSec * 1000),
                        'endMs' => (int) round($wordEndSec * 1000),
                    ];
                    $currentWord = '';
                    $wordStartSec = null;
                    $wordEndSec = null;
                }
                continue;
            }

            if ($currentWord === '') {
                $wordStartSec = $charStart;
            }
            $currentWord .= $char;
            $wordEndSec = $charEnd;
        }

        if ($currentWord !== '' && $wordStartSec !== null && $wordEndSec !== null) {
            $words[] = [
                'word' => $currentWord,
                'startMs' => (int) round($wordStartSec * 1000),
                'endMs' => (int) round($wordEndSec * 1000),
            ];
        }

        // Enforce monotonicity and non-zero duration
        return $this->enforceMonotonicity($words);
    }

    /**
     * Ensure word timings are strictly monotonic with startMs < endMs.
     *
     * @param list<array{word: string, startMs: int, endMs: int}> $words
     * @return list<array{word: string, startMs: int, endMs: int}>
     */
    protected function enforceMonotonicity(array $words): array
    {
        $result = [];
        $lastEnd = 0;

        foreach ($words as $w) {
            $start = max($lastEnd, $w['startMs']);
            $end = max($start + 20, $w['endMs']); // Minimum 20ms per word

            $result[] = [
                'word' => $w['word'],
                'startMs' => $start,
                'endMs' => $end,
            ];

            $lastEnd = $end;
        }

        return $result;
    }
}

