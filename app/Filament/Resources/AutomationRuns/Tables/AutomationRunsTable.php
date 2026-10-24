<?php

namespace App\Filament\Resources\AutomationRuns\Tables;

use App\Enums\AutomationRunStatus;
use App\Enums\AutomationTrigger;
use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use App\Models\AutomationRun;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AutomationRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('pipeline')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('trigger')
                    ->badge(),
                IconColumn::make('dry_run')
                    ->label('Dry run')
                    ->boolean(),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->placeholder('—'),
                TextColumn::make('budget_spent_micros')
                    ->label('Spent')
                    ->formatStateUsing(fn (int|string $state): string => '$'.number_format(((int) $state) / 1_000_000, 6))
                    ->sortable(),
                TextColumn::make('budget_reserved_micros')
                    ->label('Reserved')
                    ->formatStateUsing(fn (int|string $state): string => '$'.number_format(((int) $state) / 1_000_000, 6))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(self::statusOptions()),
                SelectFilter::make('trigger')
                    ->options(self::triggerOptions()),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (AutomationRun $record): string => AutomationRunResource::getUrl('view', ['record' => $record]));
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return collect(AutomationRunStatus::cases())
            ->mapWithKeys(fn (AutomationRunStatus $status): array => [$status->value => $status->getLabel()])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function triggerOptions(): array
    {
        return collect(AutomationTrigger::cases())
            ->mapWithKeys(fn (AutomationTrigger $trigger): array => [$trigger->value => $trigger->getLabel()])
            ->all();
    }
}
