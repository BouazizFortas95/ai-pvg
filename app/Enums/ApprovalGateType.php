<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ApprovalGateType: string implements HasColor, HasLabel
{
    case AutoGreenlight = 'auto_greenlight';
    case PaidProvider = 'paid_provider';
    case PublicPublish = 'public_publish';
    case AssetLicense = 'asset_license';
    case BulkRender = 'bulk_render';
    case ScriptFactCheck = 'script_fact_check';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::AutoGreenlight => 'Auto-greenlight',
            self::PaidProvider => 'Paid provider',
            self::PublicPublish => 'Public publish',
            self::AssetLicense => 'Asset license',
            self::BulkRender => 'Bulk render',
            self::ScriptFactCheck => 'Script fact check',
        };
    }

    public function getColor(): ?string
    {
        return match ($this) {
            self::AutoGreenlight => 'info',
            self::PaidProvider => 'warning',
            self::PublicPublish => 'danger',
            self::AssetLicense => 'warning',
            self::BulkRender => 'gray',
            self::ScriptFactCheck => 'warning',
        };
    }
}
