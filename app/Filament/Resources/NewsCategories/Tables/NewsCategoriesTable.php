<?php

namespace App\Filament\Resources\NewsCategories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NewsCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Category name'))
                    ->searchable()
                    ->sortable()
                    ->weight('semibold')
                    ->color(fn ($state, $record) => $record->color)
                    ->icon(fn ($record) => $record->icon),

                TextColumn::make('slug')
                    ->label(__('Slug'))
                    ->color('gray')
                    ->sortable(),

                TextColumn::make('color')
                    ->label(__('Color'))
                    ->placeholder('—')
                    ->badge()
                    ->color(fn ($state) => $state ?? 'gray'),

                IconColumn::make('is_active')
                    ->label(__('Active status'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('news_count')
                    ->label(__('Articles'))
                    ->counts('news')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Creation date'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('Active status')),
            ])
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencil),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name', 'asc');
    }
}
