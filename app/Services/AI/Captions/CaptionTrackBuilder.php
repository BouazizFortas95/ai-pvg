<?php

namespace App\Services\AI\Captions;

class CaptionTrackBuilder
{
    public function buildChunks(string $text, string $language, int $maxCharsPerLine = 42, int $maxWordsPerChunk = 10): array
    {
        $isRtl = in_array($language, ['ar', 'ar-sa', 'ar-eg', 'ar-dz', 'fa', 'he'], true);
        $words = array_filter(preg_split('/\s+/', $text));
        $chunks = [];
        $currentChunk = [];
        $currentLength = 0;

        foreach ($words as $word) {
            $len = mb_strlen($word, 'UTF-8');
            if (count($currentChunk) >= $maxWordsPerChunk || ($currentLength + $len + 1 > $maxCharsPerLine)) {
                $chunks[] = [
                    'text' => implode(' ', $currentChunk),
                    'language' => $language,
                    'rtl' => $isRtl,
                ];
                $currentChunk = [$word];
                $currentLength = $len;
            } else {
                $currentChunk[] = $word;
                $currentLength += $len + (empty($currentChunk) ? 0 : 1);
            }
        }
        if (! empty($currentChunk)) {
            $chunks[] = [
                'text' => implode(' ', $currentChunk),
                'language' => $language,
                'rtl' => $isRtl,
            ];
        }

        return $chunks;
    }
}
