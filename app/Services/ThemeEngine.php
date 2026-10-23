<?php
namespace App\Services;
use App\Models\UserThemePreference;
class ThemeEngine
{
    public static function getThemeForUser(int $userId): array
    {
        $pref = UserThemePreference::where('user_id', $userId)->first();
        if ($pref) {
            return [
                '--primary' => $pref->primary_color,
                '--secondary' => $pref->secondary_color,
                '--accent' => $pref->accent_color,
                '--bg' => $pref->background_color,
                '--text' => $pref->text_color,
                '--radius' => $pref->border_radius.'px',
                '--mode' => $pref->mode,
            ];
        }
        return [
            '--primary' => '#00d4ff',
            '--secondary' => '#1e293b',
            '--accent' => '#64748b',
            '--bg' => '#0f172a',
            '--text' => '#f8fafc',
            '--radius' => '8px',
            '--mode' => 'dark',
        ];
    }

    public static function saveForUser(int $userId, array $data): void
    {
        UserThemePreference::updateOrCreate(
            ['user_id' => $userId],
            array_merge(['theme_name' => 'Custom'], $data)
        );
    }
}
