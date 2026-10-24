<?php

namespace App\Filament\Resources\ScriptFactChecks\Pages;

use App\Filament\Resources\ScriptFactChecks\ScriptFactCheckResource;
use Filament\Resources\Pages\ListRecords;

class ListScriptFactChecks extends ListRecords
{
    protected static string $resource = ScriptFactCheckResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
