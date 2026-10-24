<?php

namespace App\Filament\Resources\StoryClusters\Tables;

use App\Enums\StoryClusterStatus;
use App\Filament\Resources\StoryClusters\StoryClusterResource;
use App\Models\StoryCluster;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class StoryClustersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('canonical_title')
                    ->label('Canonical title')
                    ->searchable()
                    ->limit(56)
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_count')
                    ->label('Sources')
                    ->sortable(),
                TextColumn::make('confidence')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('window_start')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('window_end')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (StoryCluster $record): string => StoryClusterResource::getUrl('view', ['record' => $record]));
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(StoryClusterStatus::cases())
            ->mapWithKeys(fn (StoryClusterStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
    }
}
