<?php

namespace App\Filament\Resources\ApprovalGates\Pages;

use App\Filament\Resources\ApprovalGates\ApprovalGateResource;
use Filament\Resources\Pages\ViewRecord;

class ViewApprovalGate extends ViewRecord
{
    protected static string $resource = ApprovalGateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
