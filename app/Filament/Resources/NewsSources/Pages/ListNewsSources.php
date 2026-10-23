<?php

namespace App\Filament\Resources\NewsSources\Pages;

use App\Filament\Resources\NewsSources\NewsSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNewsSources extends ListRecords
{
    protected static string $resource = NewsSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
