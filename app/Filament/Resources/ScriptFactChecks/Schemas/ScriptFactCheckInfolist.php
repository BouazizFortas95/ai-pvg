<?php

namespace App\Filament\Resources\ScriptFactChecks\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScriptFactCheckInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Claim')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('videoScript.title')
                            ->label('Script')
                            ->columnSpan(2),
                        TextEntry::make('claim')
                            ->columnSpanFull(),
                        TextEntry::make('verdict')->badge(),
                        TextEntry::make('critic_score')
                            ->label('Critic score')
                            ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2)),
                        TextEntry::make('checker_version')->label('Checker'),
                        TextEntry::make('source_news_ids')
                            ->label('Explicit source news ids')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state) && $state !== []
                                ? '#'.implode(', #', array_map('intval', $state))
                                : '—'),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make('Supporting evidence')
                    ->schema([
                        TextEntry::make('evidence')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Metadata')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('meta')
                            ->label('')
                            ->placeholder('—')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : (string) ($state ?? '—'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
