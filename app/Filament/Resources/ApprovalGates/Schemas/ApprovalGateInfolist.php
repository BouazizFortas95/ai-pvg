<?php

namespace App\Filament\Resources\ApprovalGates\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApprovalGateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Gate')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('id'),
                        TextEntry::make('gate_type')
                            ->label('Gate type')
                            ->badge(),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('gateable_type')
                            ->label('Subject type')
                            ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—'),
                        TextEntry::make('gateable_id')
                            ->label('Subject ID'),
                        TextEntry::make('requestedBy.name')
                            ->label('Requested by')
                            ->placeholder('System'),
                        TextEntry::make('expires_at')
                            ->dateTime()
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
                        TextEntry::make('decider.name')
                            ->label('Decided by')
                            ->placeholder('—'),
                        TextEntry::make('decided_at')
                            ->label('Decided at')
                            ->dateTime()
                            ->placeholder('—'),
                    ]),
                Section::make('Decision')
                    ->schema([
                        TextEntry::make('decision_note')
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
}
