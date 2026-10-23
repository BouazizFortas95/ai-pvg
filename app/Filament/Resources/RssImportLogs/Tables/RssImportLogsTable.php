<?php

namespace App\Filament\Resources\RssImportLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RssImportLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('newsSource.name')
                    ->label('Source')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('total_items')
                    ->label('Total')
                    ->numeric(),
                TextColumn::make('imported_items')
                    ->label('Imported')
                    ->numeric(),
                TextColumn::make('duplicate_items')
                    ->label('Duplicates')
                    ->numeric(),
                TextColumn::make('failed_items')
                    ->label('Failed')
                    ->numeric(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->defaultSort('started_at', 'desc');
    }
}
