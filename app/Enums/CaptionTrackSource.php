<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CaptionTrackSource: string implements HasLabel
{
    case Estimated = 'estimated';
    case Verified = 'verified';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Estimated => 'Estimated',
            self::Verified => 'Verified',
        };
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }
}
