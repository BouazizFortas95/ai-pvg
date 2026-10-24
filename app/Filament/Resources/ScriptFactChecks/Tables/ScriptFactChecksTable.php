<?php

namespace App\Filament\Resources\ScriptFactChecks\Tables;

use App\Enums\ScriptFactCheckVerdict;
use App\Filament\Resources\ScriptFactChecks\ScriptFactCheckResource;
use App\Models\ScriptFactCheck;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScriptFactChecksTable
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
                    ->limit(36)
                    ->wrap(),
                TextColumn::make('claim')
                    ->wrap()
                    ->limit(90)
                    ->searchable(),
                TextColumn::make('verdict')
                    ->badge()
                    ->sortable(),
                TextColumn::make('source_news_ids')
                    ->label('Source news')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state) && $state !== []
                        ? '#'.implode(', #', array_map('intval', $state))
                        : '—'),
                TextColumn::make('critic_score')
                    ->label('Critic')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('verdict')
                    ->options(collect(ScriptFactCheckVerdict::cases())
                        ->mapWithKeys(fn (ScriptFactCheckVerdict $verdict): array => [$verdict->value => $verdict->getLabel()])
                        ->all()),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (ScriptFactCheck $record): string => ScriptFactCheckResource::getUrl('view', ['record' => $record]));
    }
}
