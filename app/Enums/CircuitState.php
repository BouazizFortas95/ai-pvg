<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CircuitState: string implements HasColor, HasLabel
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Closed => 'Closed',
            self::Open => 'Open',
            self::HalfOpen => 'Half-open',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Closed => 'success',
            self::Open => 'danger',
            self::HalfOpen => 'warning',
        };
    }
}
