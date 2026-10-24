<?php

namespace App\Filament\Resources\ApprovalGates\Pages;

use App\Filament\Resources\ApprovalGates\ApprovalGateResource;
use Filament\Resources\Pages\ListRecords;

class ListApprovalGates extends ListRecords
{
    protected static string $resource = ApprovalGateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
