<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PresetFormat: string implements HasLabel
{
    case Shorts916 = 'youtube_shorts_9_16';
    case Long169 = 'youtube_long_16_9';
    case Square11 = 'instagram_square_1_1';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Shorts916 => '9:16 Short (1080x1920)',
            self::Long169 => '16:9 Long (1920x1080)',
            self::Square11 => '1:1 Square (1080x1080)',
        };
    }

    public function resolution(): array
    {
        return match ($this) {
            self::Shorts916 => ['width' => 1080, 'height' => 1920],
            self::Long169 => ['width' => 1920, 'height' => 1080],
            self::Square11 => ['width' => 1080, 'height' => 1080],
        };
    }
}
