<?php

namespace App\Filament\Resources\VideoScripts\Tables;

use App\Enums\VideoScriptStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VideoScriptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Script Title')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('news.title')
                    ->label('Source News')
                    ->searchable(),
                TextColumn::make('language')
                    ->label('Language')
                    ->badge(),
                TextColumn::make('target_duration')
                    ->label('Target (s)')
                    ->numeric(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn ($state) => $state instanceof VideoScriptStatus ? $state->getLabel() : (string) $state)
                    ->badge()
                    ->color(fn ($state) => ($state instanceof VideoScriptStatus ? $state->getColor() : 'gray')),
                TextColumn::make('ai_provider')
                    ->label('AI Provider')
                    ->placeholder('—'),
                TextColumn::make('generated_at')
                    ->label('Generated')
                    ->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'generating' => 'Generating',
                        'generated' => 'Generated',
                        'approved' => 'Approved',
                        'rejected' => 'Rejected',
                        'failed' => 'Failed',
                    ]),
                SelectFilter::make('language')
                    ->label('Language')
                    ->options([
                        'en' => 'English',
                        'ar' => 'Arabic',
                        'fr' => 'French',
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
