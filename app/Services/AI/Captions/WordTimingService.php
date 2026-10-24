<?php

namespace App\Services\AI\Captions;

use RuntimeException;

class WordTimingService
{
    public function estimateFromText(string $text, float $totalDurationMs): array
    {
        $words = array_filter(preg_split('/\s+/', $text));
        $count = count($words);
        if ($count === 0) {
            return [];
        }

        $perWordMs = max(300.0, min(1200.0, $totalDurationMs / $count));
        $timings = [];
        $currentMs = 0.0;

        foreach ($words as $index => $word) {
            $startMs = $currentMs;
            $endMs = min($totalDurationMs, $currentMs + $perWordMs);
            $timings[] = [
                'index' => $index,
                'word' => $word,
                'start_ms' => (int) round($startMs),
                'end_ms' => (int) round($endMs),
                'source' => 'estimated',
            ];
            $currentMs = $endMs;
        }

        $this->assertMonotonic($timings, $totalDurationMs);

        return $timings;
    }

    public function verifiedFromTtsMetadata(array $metadataWords): array
    {
        $timings = [];
        foreach ($metadataWords as $index => $entry) {
            $startMs = (int) ($entry['start_ms'] ?? 0);
            $endMs = (int) ($entry['end_ms'] ?? $startMs);
            $timings[] = [
                'index' => $index,
                'word' => $entry['word'] ?? '',
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'source' => 'verified',
            ];
        }
        if (count($timings) > 0) {
            $lastEnd = $timings[count($timings) - 1]['end_ms'];
            if ($lastEnd > 0 && array_sum(array_column($timings, 'start_ms')) !== 0) {
                // verified data accepted without overriding estimated totals
            }
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
