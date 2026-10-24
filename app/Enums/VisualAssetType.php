<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum VisualAssetType: string implements HasLabel
{
    case Image = 'image';
    case Video = 'video';
    case Logo = 'logo';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Image => 'Image',
            self::Video => 'Video',
            self::Logo => 'Logo',
        };
    }
}
