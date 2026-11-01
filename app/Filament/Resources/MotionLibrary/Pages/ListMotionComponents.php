<?php

namespace App\Filament\Resources\MotionLibrary\Pages;

use App\Filament\Resources\MotionLibrary\MotionComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMotionComponents extends ListRecords
{
    protected static string $resource = MotionComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

