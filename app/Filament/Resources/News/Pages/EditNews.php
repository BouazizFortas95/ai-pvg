<?php

namespace App\Filament\Resources\News\Pages;

use App\Filament\Resources\News\NewsResource;
use App\Jobs\GenerateVideoScriptJob;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNews extends EditRecord
{
    protected static string $resource = NewsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate_script')
                ->label('Generate Video Script')
                ->icon('heroicon-o-film')
                ->visible(fn () => $this->record !== null)
                ->action(function () {
                    $script = \App\Models\VideoScript::create([
                        'news_id' => $this->record->getKey(),
                        'title' => $this->record->title,
                        'language' => 'en',
                        'target_duration' => 45,
                        'status' => 'draft',
                    ]);
                    GenerateVideoScriptJob::dispatch($script->getKey());
                    $this->notify('success', 'Video script generation queued.');
                }),
            DeleteAction::make(),
        ];
    }
}
