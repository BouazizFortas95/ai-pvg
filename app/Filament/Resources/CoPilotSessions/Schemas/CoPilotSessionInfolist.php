<?php

namespace App\Filament\Resources\CoPilotSessions\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CoPilotSessionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Co-Pilot review')
                    ->description('Append-only administrator review record. This view cannot publish or mutate the reviewed item.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('user.name')
                            ->label('Reviewer')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('target_type')
                            ->label('Target type')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('target_id')
                            ->label('Target ID'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('action')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                    ]),
                Section::make('Review note')
                    ->schema([
                        TextEntry::make('note')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
