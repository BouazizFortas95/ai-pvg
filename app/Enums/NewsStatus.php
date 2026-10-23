<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum NewsStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Processed = 'processed';
    case Rejected = 'rejected';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Ready => 'Ready',
            self::Processed => 'Processed',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ready => 'success',
            self::Processed => 'info',
            self::Rejected => 'danger',
        };
    }
}
