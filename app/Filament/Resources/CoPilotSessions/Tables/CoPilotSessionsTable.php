<?php

namespace App\Filament\Resources\CoPilotSessions\Tables;

use App\Filament\Resources\CoPilotSessions\CoPilotSessionResource;
use App\Models\CoPilotReviewSession;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CoPilotSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Reviewer')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('target_type')
                    ->label('Target')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->sortable(),
                TextColumn::make('target_id')
                    ->label('Target ID')
                    ->sortable(),
                TextColumn::make('action')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === CoPilotReviewSession::ACTION_APPROVE ? 'success' : 'danger')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('note')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options([
                        CoPilotReviewSession::ACTION_APPROVE => 'Approve',
                        CoPilotReviewSession::ACTION_REJECT => 'Reject',
                    ]),
                SelectFilter::make('target_type')
                    ->label('Target')
                    ->options([
                        CoPilotReviewSession::TARGET_SCRIPT => 'Script',
                        CoPilotReviewSession::TARGET_RENDER => 'Render',
                        CoPilotReviewSession::TARGET_ASSET => 'Asset',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (CoPilotReviewSession $record): string => CoPilotSessionResource::getUrl('view', ['record' => $record]));
    }
}
