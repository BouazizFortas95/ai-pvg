<?php

namespace App\Filament\Resources\NewsScores\Pages;

use App\Filament\Resources\NewsScores\NewsScoreResource;
use Filament\Resources\Pages\ViewRecord;

class ViewNewsScore extends ViewRecord
{
    protected static string $resource = NewsScoreResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
