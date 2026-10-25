<?php

namespace App\Filament\Resources\PublishingStates\Pages;

use App\Filament\Resources\PublishingStates\PublishingStateResource;
use Filament\Resources\Pages\ListRecords;

class ListPublishingStates extends ListRecords
{
    protected static string $resource = PublishingStateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
