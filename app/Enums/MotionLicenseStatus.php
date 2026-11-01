<?php

namespace App\Enums;

enum MotionLicenseStatus: string
{
    case ReferenceOnly = 'reference_only';
    case Cleared = 'cleared';
    case Original = 'original';

    public function getLabel(): string
    {
        return match ($this) {
            self::ReferenceOnly => 'Reference Only (Inspiration)',
            self::Cleared => 'Cleared / Licensed',
            self::Original => 'Original In-House',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ReferenceOnly => 'warning',
            self::Cleared => 'info',
            self::Original => 'success',
        };
    }

    /**
     * Safety taxonomy rule: reference_only components can NEVER be shipped or
     * rendered into real video exports unless cleared or authored as original.
     */
    public function canBeRendered(): bool
    {
        return match ($this) {
            self::ReferenceOnly => false,
            self::Cleared, self::Original => true,
        };
    }
}

