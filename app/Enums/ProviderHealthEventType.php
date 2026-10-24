<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProviderHealthEventType: string implements HasColor, HasLabel
{
    case RateLimit = 'rate_limit';
    case Quota = 'quota';
    case Auth = 'auth';
    case Timeout = 'timeout';
    case Error = 'error';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::RateLimit => 'Rate limit',
            self::Quota => 'Quota',
            self::Auth => 'Authentication',
            self::Timeout => 'Timeout',
            self::Error => 'Error',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::RateLimit => 'warning',
            self::Quota => 'danger',
            self::Auth => 'danger',
            self::Timeout => 'warning',
            self::Error => 'danger',
        };
    }
}
