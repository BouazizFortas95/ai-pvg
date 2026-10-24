<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TriageBand: string implements HasColor, HasLabel
{
    case AutoGreenlight = 'auto_greenlight';
    case Review = 'review';
    case BelowThreshold = 'below_threshold';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AutoGreenlight => 'Auto-greenlight',
            self::Review => 'Human review',
            self::BelowThreshold => 'Below threshold',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::AutoGreenlight => 'success',
            self::Review => 'warning',
            self::BelowThreshold => 'gray',
        };
    }

    public static function fromScore(float $score, float $autoGreenlightMin, float $reviewBandMin): self
    {
        if ($reviewBandMin > $autoGreenlightMin) {
            $reviewBandMin = $autoGreenlightMin;
        }

        return match (true) {
            $score >= $autoGreenlightMin => self::AutoGreenlight,
            $score >= $reviewBandMin => self::Review,
            default => self::BelowThreshold,
        };
    }
}
