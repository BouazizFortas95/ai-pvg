<?php

namespace App\Filament\Resources\PublishingStates\Tables;

use App\Filament\Resources\PublishingStates\PublishingStateResource;
use App\Models\PublishingApprovalSession;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PublishingStatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('videoScript.title')
                    ->label('Script')
                    ->searchable()
                    ->limit(40)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        PublishingApprovalSession::STATUS_APPROVED => 'success',
                        PublishingApprovalSession::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),
                TextColumn::make('firstApprover.name')
                    ->label('First approver')
                    ->placeholder('—'),
                TextColumn::make('first_approved_at')
                    ->label('First approved')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('secondApprover.name')
                    ->label('Second approver')
                    ->placeholder('—'),
                TextColumn::make('second_approved_at')
                    ->label('Second approved')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('rejection_reason')
                    ->label('Rejection reason')
                    ->limit(50)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        PublishingApprovalSession::STATUS_PENDING => 'Pending',
                        PublishingApprovalSession::STATUS_APPROVED => 'Approved',
                        PublishingApprovalSession::STATUS_REJECTED => 'Rejected',
                    ]),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (PublishingApprovalSession $record): string => PublishingStateResource::getUrl('view', ['record' => $record]));
    }
}
