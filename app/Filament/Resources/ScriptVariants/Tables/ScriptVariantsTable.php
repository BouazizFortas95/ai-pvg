<?php

namespace App\Filament\Resources\ScriptVariants\Tables;

use App\Enums\ScriptVariantType;
use App\Filament\Resources\ScriptVariants\ScriptVariantResource;
use App\Models\ScriptVariant;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScriptVariantsTable
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
                    ->wrap(),
                TextColumn::make('hook_index')
                    ->label('Hook #')
                    ->sortable(),
                TextColumn::make('hook_type')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('content')
                    ->label('Grounded hook')
                    ->wrap()
                    ->limit(90)
                    ->searchable(),
                TextColumn::make('selected')
                    ->label('Selected')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state ? 'Yes' : 'No')
                    ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('score')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('source_news_ids')
                    ->label('Source news')
                    ->formatStateUsing(fn (mixed $state): string => is_array($state)
                        ? '#'.implode(', #', $state)
                        : '—'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('variant_type')
                    ->options(collect(ScriptVariantType::cases())
                        ->mapWithKeys(fn (ScriptVariantType $type): array => [$type->value => $type->getLabel()])
                        ->all()),
                SelectFilter::make('selected')
                    ->options(['1' => 'Selected', '0' => 'Not selected']),
            ])
            ->defaultSort('id', 'desc')
            ->recordUrl(fn (ScriptVariant $record): string => ScriptVariantResource::getUrl('view', ['record' => $record]));
    }
}
