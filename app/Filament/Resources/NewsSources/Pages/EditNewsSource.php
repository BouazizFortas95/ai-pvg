<?php

namespace App\Filament\Resources\NewsSources\Pages;

use App\Filament\Resources\NewsSources\NewsSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsSource extends EditRecord
{
    protected static string $resource = NewsSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
