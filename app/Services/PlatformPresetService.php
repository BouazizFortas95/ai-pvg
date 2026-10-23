<?php

namespace App\Services;

class PlatformPresetService
{
    public static array $presets = [
        'youtube_shorts' => ['aspect' => '9:16', 'resolution' => '1080x1920', 'min_duration' => 15, 'max_duration' => 60, 'recommended_duration' => 30, 'fields' => ['title', 'description', 'hashtags']],
        'tiktok' => ['aspect' => '9:16', 'resolution' => '1080x1920', 'min_duration' => 15, 'max_duration' => 60, 'recommended_duration' => 30, 'fields' => ['title', 'description', 'hashtags']],
        'facebook_reels' => ['aspect' => '9:16', 'resolution' => '1080x1920', 'min_duration' => 15, 'max_duration' => 60, 'recommended_duration' => 30, 'fields' => ['title', 'description', 'hashtags']],
    ];

    public static function get(string $key): array
    {
        return static::$presets[$key] ?? static::$presets['youtube_shorts'];
    }

    public static function validate(string $key, int $duration, string $resolution): array
    {
        $p = static::get($key);
        $issues = [];
        if ($duration < $p['min_duration']) {
            $issues[] = 'Duration below preset minimum';
        }
        if ($duration > $p['max_duration']) {
            $issues[] = 'Duration above preset maximum';
        }
        if ($resolution !== $p['resolution']) {
            $issues[] = 'Resolution does not match preset';
        }

        return ['valid' => empty($issues), 'issues' => $issues, 'preset' => $p];
    }
}
