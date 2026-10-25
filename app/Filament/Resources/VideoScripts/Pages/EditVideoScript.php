<?php

namespace App\Filament\Resources\VideoScripts\Pages;

use App\Filament\Resources\VideoScripts\VideoScriptResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditVideoScript extends EditRecord
{
    protected static string $resource = VideoScriptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label('Approve')
                ->icon('heroicon-o-check-circle')
                ->visible(fn () => $this->record && $this->record->status !== 'approved')
                ->action(function () {
                    $this->record->update([
                        'status' => 'approved',
                        'approved_at' => now(),
                        'approved_by' => auth()->id(),
                    ]);
                    $this->notify('success', 'Script approved.');
                }),
            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record && $this->record->status !== 'rejected' && $this->record->status !== 'approved')
                ->action(function () {
                    $this->record->update(['status' => 'rejected']);
                    $this->notify('warning', 'Script rejected.');
                }),
        ];
    }
}
