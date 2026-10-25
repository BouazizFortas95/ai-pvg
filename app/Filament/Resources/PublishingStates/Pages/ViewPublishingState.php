<?php

namespace App\Filament\Resources\PublishingStates\Pages;

use App\Filament\Resources\PublishingStates\PublishingStateResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPublishingState extends ViewRecord
{
    protected static string $resource = PublishingStateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
