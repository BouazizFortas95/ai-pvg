<?php

namespace App\Filament\Resources\ApprovalGates\Tables;

use App\Enums\ApprovalGateStatus;
use App\Enums\ApprovalGateType;
use App\Filament\Resources\ApprovalGates\ApprovalGateResource;
use App\Models\ApprovalGate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ApprovalGatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('gate_type')
                    ->label('Gate')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('gateable_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->placeholder('—'),
                TextColumn::make('gateable_id')
                    ->label('Subject ID')
                    ->sortable(),
                TextColumn::make('requestedBy.name')
                    ->label('Requested by')
                    ->placeholder('System'),
                TextColumn::make('firstApprover.name')
                    ->label('First approver')
                    ->placeholder('—'),
                TextColumn::make('secondApprover.name')
                    ->label('Second approver')
                    ->placeholder('—'),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('decided_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('gate_type')
                    ->label('Gate type')
                    ->options(self::typeOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (ApprovalGate $record): string => ApprovalGateResource::getUrl('view', ['record' => $record]));
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(ApprovalGateStatus::cases())
            ->mapWithKeys(fn (ApprovalGateStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        return collect(ApprovalGateType::cases())
            ->mapWithKeys(fn (ApprovalGateType $type): array => [$type->value => $type->getLabel()])
            ->all();
    }
}
