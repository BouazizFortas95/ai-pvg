<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ScriptFactCheckVerdict: string implements HasColor, HasLabel
{
    case Supported = 'supported';
    case Unsupported = 'unsupported';
    case Uncertain = 'uncertain';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Supported => 'Supported',
            self::Unsupported => 'Unsupported',
            self::Uncertain => 'Uncertain',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Supported => 'success',
            self::Unsupported => 'danger',
            self::Uncertain => 'warning',
        };
    }

    public function blocksApproval(): bool
    {
        return $this !== self::Supported;
    }
}
