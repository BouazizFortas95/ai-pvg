<?php

namespace App\Filament\Resources\ScriptVariants\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ScriptVariantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Variant')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('videoScript.title')
                            ->label('Script')
                            ->columnSpan(2),
                        TextEntry::make('variant_key')
                            ->label('Deterministic key')
                            ->columnSpan(2),
                        TextEntry::make('variant_type')->badge(),
                        TextEntry::make('hook_index')->label('Hook index'),
                        TextEntry::make('hook_type')->badge()->placeholder('—'),
                        TextEntry::make('selected')
                            ->badge()
                            ->formatStateUsing(fn (mixed $state): string => $state ? 'Selected' : 'Not selected')
                            ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
                        TextEntry::make('score')
                            ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2)),
                        TextEntry::make('source_news_ids')
                            ->label('Explicit source news ids')
                            ->formatStateUsing(self::ids(...)),
                        TextEntry::make('prompt_version')->placeholder('—'),
                        TextEntry::make('model_version')->placeholder('—'),
                    ]),
                Section::make('Content')
                    ->schema([
                        TextEntry::make('content')
                            ->columnSpanFull(),
                        TextEntry::make('retention_rationale')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
                Section::make('Metadata')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('meta')
                            ->label('')
                            ->placeholder('—')
                            ->formatStateUsing(self::json(...))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function ids(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '—';
        }

        return '#'.implode(', #', array_map('intval', $state));
    }

    private static function json(mixed $state): string
    {
        return is_array($state)
            ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : (string) ($state ?? '—');
    }
}
