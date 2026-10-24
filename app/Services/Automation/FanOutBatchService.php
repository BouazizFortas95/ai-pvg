<?php

namespace App\Services\Automation;

use App\Enums\PresetFormat;
use Illuminate\Support\Str;

class FanOutBatchService
{
    /**
     * Deterministic planned fan-out from a verified render. Returns planned rows
     * and midpoint thumbnails. Actual render execution is never performed here;
     * callers stage batch jobs. Writes are dry-run only unless called directly.
     */
    public function planFanOut(int $videoRenderId): array
    {
        $variants = [];
        foreach (PresetFormat::cases() as $preset) {
            $res = $preset->resolution();
            $variants[] = [
                'video_render_id' => $videoRenderId,
                'preset' => $preset->value,
                'width' => $res['width'],
                'height' => $res['height'],
                'frame_offset_ms' => null, // thumbnail extracted at midpoint by ThumbnailBuilder
            ];
        }

        return [
            'video_render_id' => $videoRenderId,
            'variants' => $variants,
            'script_props' => [],
            'dry_run' => true,
        ];
    }

    public function keyFor(int $videoRenderId, string $preset): string
    {
        return 'render-'.$videoRenderId.':'.$preset;
    }

    public function canonicalKey(): string
    {
        return implode(':', [config('app.name') === 'AI-PVG' ? 'visionary' : Str::lower('visionary'), 'g06-v1']);
    }
}
