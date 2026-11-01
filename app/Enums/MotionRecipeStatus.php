<?php

namespace App\Enums;

enum MotionRecipeStatus: string
{
    case Imported = 'imported';
    case Authored = 'authored';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Imported => 'Imported (Pending Authoring)',
            self::Authored => 'Authored Component',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Imported => 'info',
            self::Authored => 'success',
            self::Rejected => 'danger',
        };
    }
}

