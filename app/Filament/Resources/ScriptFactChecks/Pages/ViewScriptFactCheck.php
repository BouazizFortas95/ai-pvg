<?php

namespace App\Filament\Resources\ScriptFactChecks\Pages;

use App\Filament\Resources\ScriptFactChecks\ScriptFactCheckResource;
use Filament\Resources\Pages\ViewRecord;

class ViewScriptFactCheck extends ViewRecord
{
    protected static string $resource = ScriptFactCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
