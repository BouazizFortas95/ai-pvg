<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VideoScriptStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Generating = 'generating';
    case Generated = 'generated';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Generating => 'Generating',
            self::Generated => 'Generated',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Generating => 'info',
            self::Generated => 'success',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Failed => 'danger',
        };
    }
}
