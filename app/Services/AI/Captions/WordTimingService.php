<?php

namespace App\Services\AI\Captions;

use RuntimeException;

class WordTimingService
{
    /**
     * Estimate monotonic word-level timings from raw text distributed across the available duration.
     *
     * @return list<array{index: int, word: string, start_ms: int, end_ms: int, source: string}>
     */
    public function estimateFromText(string $text, float $totalDurationMs): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($text))));
        $count = count($words);
        if ($count === 0) {
            return [];
        }

        // Each word takes an equal slice of duration, capped at 1200ms for short texts
        $slice = $totalDurationMs / $count;
        if ($slice > 1200.0) {
            $slice = 1200.0;
        }

        // Guarantee at least 1ms per word
        $slice = max(1.0, $slice);
        $effectiveDurationMs = max($totalDurationMs, $count * $slice);

        $timings = [];
        $currentMs = 0;

        foreach ($words as $index => $word) {
            $startMs = $currentMs;
            $targetEndMs = (int) round(($index + 1) * $slice);
            // Ensure end_ms is strictly greater than start_ms
            $endMs = max($startMs + 1, $targetEndMs);

            $timings[] = [
                'index' => $index,
                'word' => $word,
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'source' => 'estimated',
            ];
            $currentMs = $endMs;
        }

        $this->assertMonotonic($timings, $effectiveDurationMs);

        return $timings;
    }

    /**
     * Build monotonic verified timings from TTS metadata.
     *
     * @param list<array<string, mixed>> $metadataWords
     * @return list<array{index: int, word: string, start_ms: int, end_ms: int, source: string}>
     */
    public function verifiedFromTtsMetadata(array $metadataWords): array
    {
        $timings = [];
        $prevEnd = 0;

        foreach ($metadataWords as $index => $entry) {
            $rawStart = (int) ($entry['start_ms'] ?? $prevEnd);
            $startMs = max($prevEnd, $rawStart);
            $rawEnd = (int) ($entry['end_ms'] ?? ($startMs + 50));
            $endMs = max($startMs + 1, $rawEnd);

            $timings[] = [
                'index' => $index,
                'word' => (string) ($entry['word'] ?? ''),
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'source' => 'verified',
            ];

            $prevEnd = $endMs;
        }

        $this->assertMonotonic($timings, PHP_FLOAT_MAX);

        return $timings;
    }

    private function assertMonotonic(array $timings, float $maxMs): void
    {
        $prevEnd = 0;
        foreach ($timings as $t) {
            if ($t['start_ms'] < $prevEnd) {
                throw new RuntimeException('Caption word timings are not monotonic.');
            }
            if ($t['start_ms'] >= $t['end_ms']) {
                throw new RuntimeException('Caption start_ms must be less than end_ms.');
            }
            if ($t['end_ms'] > $maxMs + 100) {
                throw new RuntimeException('Caption end_ms exceeds allowed duration.');
            }
            $prevEnd = $t['end_ms'];
        }
    }
}
