<?php

namespace App\Filament\Resources\MotionLibrary\Pages;

use App\Filament\Resources\MotionLibrary\MotionComponentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMotionComponent extends EditRecord
{
    protected static string $resource = MotionComponentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}

