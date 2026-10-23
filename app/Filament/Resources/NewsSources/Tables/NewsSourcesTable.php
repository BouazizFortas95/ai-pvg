<?php

namespace App\Filament\Resources\NewsSources\Tables;

use App\Models\NewsSource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NewsSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Source name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),

                TextColumn::make('website_url')
                    ->label(__('Website URL'))
                    ->url(fn ($state) => $state)
                    ->limit(30)
                    ->placeholder('—'),

                TextColumn::make('language')
                    ->label(__('Language'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => NewsSource::LANGUAGES[$state] ?? $state)
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('Active status'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('news_count')
                    ->label(__('Articles'))
                    ->counts('news')
                    ->sortable(),

                TextColumn::make('last_fetched_at')
                    ->label(__('Last fetched date'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label(__('Creation date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('Active status')),

                SelectFilter::make('language')
                    ->label(__('Language'))
                    ->options(NewsSource::LANGUAGES),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
