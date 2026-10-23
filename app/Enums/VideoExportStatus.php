<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use HasLabel;

enum VideoExportStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Exporting = 'exporting';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Pending',self::Exporting => 'Exporting',self::Completed => 'Completed',self::Failed => 'Failed'
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Pending => 'gray',self::Exporting => 'info',self::Completed => 'success',self::Failed => 'danger'
        };
    }
}
