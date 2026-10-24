<?php

namespace App\Filament\Resources\AutomationRuns\Pages;

use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAutomationRun extends ViewRecord
{
    protected static string $resource = AutomationRunResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
