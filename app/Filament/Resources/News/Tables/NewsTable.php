<?php

namespace App\Filament\Resources\News\Tables;

use App\Enums\NewsStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('Article title'))
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                TextColumn::make('newsCategory.name')
                    ->label(__('Category'))
                    ->badge()
                    ->color(fn ($state) => $state ?? 'gray')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('newsSource.name')
                    ->label(__('Source'))
                    ->placeholder('—')
                    ->limit(28)
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),

                TextColumn::make('published_at')
                    ->label(__('Publication date'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label(__('Creation date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('newsCategory')
                    ->label(__('Category'))
                    ->relationship('newsCategory', 'name'),

                SelectFilter::make('newsSource')
                    ->label(__('Source'))
                    ->relationship('newsSource', 'name'),

                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(NewsStatus::class),
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
