<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use HasLabel;

enum VoiceGenerationStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Pending => 'Pending',self::Generating => 'Generating',self::Completed => 'Completed',self::Failed => 'Failed'
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::Pending => 'gray',self::Generating => 'info',self::Completed => 'success',self::Failed => 'danger'
        };
    }
}
