<?php

namespace App\Filament\Resources\NewsScores\Tables;

use App\Enums\TriageBand;
use App\Filament\Resources\NewsScores\NewsScoreResource;
use App\Models\NewsScore;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NewsScoresTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('news.title')
                    ->label('Article')
                    ->searchable()
                    ->limit(48)
                    ->wrap(),
                TextColumn::make('composite_score')
                    ->label('Composite')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('band')
                    ->badge()
                    ->sortable(),
                TextColumn::make('velocity_score')
                    ->label('Velocity')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                TextColumn::make('keyword_heat_score')
                    ->label('Keyword heat')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                TextColumn::make('novelty_score')
                    ->label('Novelty')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                TextColumn::make('source_diversity_score')
                    ->label('Source diversity')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                TextColumn::make('recency_score')
                    ->label('Recency')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(),
                TextColumn::make('scored_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('band')
                    ->options(self::bandOptions()),
            ])
            ->defaultSort('composite_score', 'desc')
            ->recordUrl(fn (NewsScore $record): string => NewsScoreResource::getUrl('view', ['record' => $record]));
    }

    /**
     * @return array<string, string>
     */
    private static function bandOptions(): array
    {
        return collect(TriageBand::cases())
            ->mapWithKeys(fn (TriageBand $band): array => [$band->value => $band->getLabel()])
            ->all();
    }
}
