<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StoryClusterStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Merged = 'merged';
    case Ready = 'ready';
    case Rejected = 'rejected';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Merged => 'Merged',
            self::Ready => 'Ready',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Open => 'info',
            self::Merged => 'gray',
            self::Ready => 'success',
            self::Rejected => 'danger',
        };
    }
}
