<?php

namespace App\Filament\Resources\CoPilotSessions\Pages;

use App\Filament\Resources\CoPilotSessions\CoPilotSessionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCoPilotSession extends ViewRecord
{
    protected static string $resource = CoPilotSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
