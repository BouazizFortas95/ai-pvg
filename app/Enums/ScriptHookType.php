<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ScriptHookType: string implements HasLabel
{
    case Curiosity = 'curiosity';
    case Contrarian = 'contrarian';
    case Stat = 'stat';
    case Question = 'question';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Curiosity => 'Curiosity',
            self::Contrarian => 'Contrarian',
            self::Stat => 'Stat',
            self::Question => 'Question',
        };
    }
}
