<?php

namespace App\Filament\Resources\CoPilotSessions\Pages;

use App\Filament\Resources\CoPilotSessions\CoPilotSessionResource;
use Filament\Resources\Pages\ListRecords;

class ListCoPilotSessions extends ListRecords
{
    protected static string $resource = CoPilotSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
