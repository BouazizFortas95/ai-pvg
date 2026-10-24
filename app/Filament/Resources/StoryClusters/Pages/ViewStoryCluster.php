<?php

namespace App\Filament\Resources\StoryClusters\Pages;

use App\Filament\Resources\StoryClusters\StoryClusterResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStoryCluster extends ViewRecord
{
    protected static string $resource = StoryClusterResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
