<?php

namespace App\Filament\Resources\NewsSources\Pages;

use App\Filament\Resources\NewsSources\NewsSourceResource;
use App\Jobs\ImportRssSourceJob;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsSource extends EditRecord
{
    protected static string $resource = NewsSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import_now')
                ->label('Import Now')
                ->icon('heroicon-o-cloud-arrow-down')
                ->visible(fn () => $this->record && $this->record->is_active && filled($this->record->rss_url))
                ->action(function () {
                    ImportRssSourceJob::dispatch($this->record->getKey());
                    $this->notify('success', 'RSS import dispatched for '.$this->record->name);
                }),
            DeleteAction::make(),
        ];
    }
}
