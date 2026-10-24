<?php

namespace App\Filament\Resources\ScriptVariants\Pages;

use App\Filament\Resources\ScriptVariants\ScriptVariantResource;
use Filament\Resources\Pages\ViewRecord;

class ViewScriptVariant extends ViewRecord
{
    protected static string $resource = ScriptVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
