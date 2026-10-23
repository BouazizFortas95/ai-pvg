<?php

namespace App\Filament\Resources\NewsSources\Pages;

use App\Filament\Resources\NewsSources\NewsSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateNewsSource extends CreateRecord
{
    protected static string $resource = NewsSourceResource::class;
}
