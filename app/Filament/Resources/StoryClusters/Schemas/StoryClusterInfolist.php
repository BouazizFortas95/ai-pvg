<?php

namespace App\Filament\Resources\StoryClusters\Schemas;

use App\Models\News;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

class StoryClusterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Cluster')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('canonical_title')
                            ->label('Canonical title')
                            ->columnSpan(2),
                        TextEntry::make('canonical_summary')
                            ->label('Canonical summary')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('source_count')->label('Sources'),
                        TextEntry::make('confidence')
                            ->formatStateUsing(fn (mixed $state): string => number_format((float) $state, 2)),
                        TextEntry::make('window_start')->dateTime()->placeholder('—'),
                        TextEntry::make('window_end')->dateTime()->placeholder('—'),
                        TextEntry::make('fingerprint')->label('Fingerprint')->placeholder('—'),
                    ]),
                Section::make('Source attribution')
                    ->schema([
                        TextEntry::make('news')
                            ->label('Members')
                            ->placeholder('—')
                            ->formatStateUsing(function (mixed $state): string {
                                if (! $state instanceof Collection) {
                                    return is_scalar($state) ? (string) $state : '—';
                                }

                                return $state
                                    ->map(function (News $news): string {
                                        $source = $news->source?->name ?? $news->source_name ?? 'unknown source';
                                        $primary = $news->pivot?->is_primary ? ' [primary]' : '';

                                        return '#'.$news->getKey().' — '.$news->title.' — '.$source.$primary;
                                    })
                                    ->implode("\n");
                            })
                            ->columnSpanFull(),
                    ]),
                Section::make('Grounded fact sheet')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('fact_sheet')
                            ->label('')
                            ->placeholder('—')
                            ->formatStateUsing(fn (mixed $state): string => is_array($state)
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : (string) $state)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
