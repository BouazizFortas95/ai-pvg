<?php

namespace App\Filament\Resources\PublishingStates\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PublishingStateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Publishing authorization')
                    ->description('Read-only dual-admin audit. No publish or upload action is exposed here.')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                        TextEntry::make('videoScript.title')
                            ->label('Script')
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->dateTime(),
                    ]),
                Section::make('Dual-control audit')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('firstApprover.name')
                            ->label('First approver')
                            ->placeholder('—'),
                        TextEntry::make('first_approved_at')
                            ->label('First approved at')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('secondApprover.name')
                            ->label('Second approver')
                            ->placeholder('—'),
                        TextEntry::make('second_approved_at')
                            ->label('Second approved at')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
                Section::make('Rejection')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('rejection_reason')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
