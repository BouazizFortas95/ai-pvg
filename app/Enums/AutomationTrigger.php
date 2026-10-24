<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AutomationTrigger: string implements HasLabel
{
    case Scheduler = 'scheduler';
    case Event = 'event';
    case Manual = 'manual';
    case AutoGreenlight = 'auto_greenlight';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Scheduler => 'Scheduler',
            self::Event => 'Event',
            self::Manual => 'Manual',
            self::AutoGreenlight => 'Auto-greenlight',
        };
    }
}
