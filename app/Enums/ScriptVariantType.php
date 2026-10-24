<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ScriptVariantType: string implements HasLabel
{
    case Hook = 'hook';
    case Title = 'title';
    case ThumbnailText = 'thumbnail_text';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Hook => 'Hook',
            self::Title => 'Title',
            self::ThumbnailText => 'Thumbnail text',
        };
    }
}
