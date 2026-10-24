<?php

namespace App\Filament\Resources\NewsScores\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NewsScoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Score')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('news.title')
                            ->label('Article')
                            ->columnSpan(2),
                        TextEntry::make('composite_score')
                            ->label('Composite score')
                            ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2)),
                        TextEntry::make('band')->badge(),
                        TextEntry::make('scored_at')->dateTime()->placeholder('—'),
                    ]),
                Section::make('Components')
                    ->columns(5)
                    ->schema([
                        TextEntry::make('velocity_score')->label('Velocity')->formatStateUsing(self::number(...)),
                        TextEntry::make('keyword_heat_score')->label('Keyword heat')->formatStateUsing(self::number(...)),
                        TextEntry::make('novelty_score')->label('Novelty')->formatStateUsing(self::number(...)),
                        TextEntry::make('source_diversity_score')->label('Source diversity')->formatStateUsing(self::number(...)),
                        TextEntry::make('recency_score')->label('Recency')->formatStateUsing(self::number(...)),
                    ]),
                Section::make('Breakdown')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('breakdown')
                            ->label('')
                            ->placeholder('—')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : (string) $state)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function number(mixed $state): string
    {
        return number_format((float) $state, 2);
    }
}
