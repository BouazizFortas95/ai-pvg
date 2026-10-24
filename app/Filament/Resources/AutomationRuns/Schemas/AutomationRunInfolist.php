<?php

namespace App\Filament\Resources\AutomationRuns\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AutomationRunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Run')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('pipeline'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('trigger')->badge(),
                        IconEntry::make('dry_run')
                            ->label('Dry run')
                            ->boolean(),
                        TextEntry::make('subject_type')
                            ->label('Subject type')
                            ->placeholder('—'),
                        TextEntry::make('subject_id')
                            ->label('Subject ID')
                            ->placeholder('—'),
                        TextEntry::make('active_window')
                            ->placeholder('—'),
                        TextEntry::make('started_at')->dateTime()->placeholder('—'),
                        TextEntry::make('completed_at')->dateTime()->placeholder('—'),
                    ]),
                Section::make('Budget')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('budget_limit_micros')
                            ->label('Limit')
                            ->formatStateUsing(self::money(...)),
                        TextEntry::make('budget_spent_micros')
                            ->label('Spent')
                            ->formatStateUsing(self::money(...)),
                        TextEntry::make('budget_reserved_micros')
                            ->label('Reserved')
                            ->formatStateUsing(self::money(...)),
                    ]),
                Section::make('Errors')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('error_code')
                            ->placeholder('—'),
                        TextEntry::make('error_message')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Metadata')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('meta')
                            ->placeholder('—')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : (string) $state),
                    ]),
            ]);
    }

    private static function money(int|string|null $state): string
    {
        return '$'.number_format(((int) $state) / 1_000_000, 6);
    }
}
