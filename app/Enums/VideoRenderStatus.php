<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VideoRenderStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Rendering = 'rendering';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Pending',self::Rendering => 'Rendering',self::Completed => 'Completed',self::Failed => 'Failed'
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Pending => 'gray',self::Rendering => 'info',self::Completed => 'success',self::Failed => 'danger'
        };
    }
}
