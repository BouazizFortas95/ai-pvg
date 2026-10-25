<?php

namespace App\Services\Automation;

use RuntimeException;

class ThumbnailBuilder
{
    public function buildMidpointThumbnail(string $videoPath, float $durationSeconds, ?int $targetMs = null): ?array
    {
        if ($durationSeconds <= 0) {
            throw new RuntimeException('Duration must be positive for thumbnail extraction.');
        }
        $midMs = $targetMs ?? (int) ($durationSeconds * 1000 / 2);

        return [
            'video_path' => $videoPath,
            'offset_ms' => $midMs,
            'width' => 1080,
            'height' => 1080,
            'dry_run' => true,
        ];
    }

    public function keyFor(int $videoRenderId): string
    {
        return 'thumbnail-'.$videoRenderId;
    }
}
