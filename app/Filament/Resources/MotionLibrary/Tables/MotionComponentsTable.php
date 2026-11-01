<?php

namespace App\Filament\Resources\MotionLibrary\Tables;

use App\Enums\MotionCategory;
use App\Enums\MotionComponentStatus;
use App\Enums\MotionLicenseStatus;
use App\Models\MotionComponent;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MotionComponentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('component_key')
                    ->label('Component Key')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->sortable(),

                TextColumn::make('version')
                    ->label('v')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('license_status')
                    ->label('License Status')
                    ->badge()
                    ->color(fn (MotionLicenseStatus|string|null $state): string => match ($state instanceof MotionLicenseStatus ? $state : MotionLicenseStatus::tryFrom((string) $state)) {
                        MotionLicenseStatus::Original => 'success',
                        MotionLicenseStatus::Cleared => 'info',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (MotionLicenseStatus|string|null $state): string => ($state instanceof MotionLicenseStatus ? $state : MotionLicenseStatus::tryFrom((string) $state))?->getLabel() ?? (string) $state),

                TextColumn::make('status')
                    ->label('Review Status')
                    ->badge()
                    ->color(fn (MotionComponentStatus|string|null $state): string => match ($state instanceof MotionComponentStatus ? $state : MotionComponentStatus::tryFrom((string) $state)) {
                        MotionComponentStatus::Approved => 'success',
                        MotionComponentStatus::Rejected => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (MotionComponentStatus|string|null $state): string => ($state instanceof MotionComponentStatus ? $state : MotionComponentStatus::tryFrom((string) $state))?->getLabel() ?? (string) $state),

                TextColumn::make('contrast_rating')
                    ->label('Contrast')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 1).':1')
                    ->sortable(),

                TextColumn::make('approver.name')
                    ->label('Approved By')
                    ->placeholder('Unapproved'),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('license_status')
                    ->label('License Status')
                    ->options([
                        MotionLicenseStatus::ReferenceOnly->value => 'Reference Only',
                        MotionLicenseStatus::Cleared->value => 'Cleared',
                        MotionLicenseStatus::Original->value => 'Original In-House',
                    ]),

                SelectFilter::make('category')
                    ->label('Category')
                    ->options(MotionCategory::options()),

                SelectFilter::make('status')
                    ->label('Review Status')
                    ->options([
                        MotionComponentStatus::PendingReview->value => 'Pending Review',
                        MotionComponentStatus::Approved->value => 'Approved',
                        MotionComponentStatus::Rejected->value => 'Rejected',
                    ]),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Approve Motion Component')
                    ->modalDescription(function (MotionComponent $record): string {
                        if ($record->license_status === MotionLicenseStatus::ReferenceOnly) {
                            return 'WARNING: This component is marked as "reference_only". Approving it will allow technique review, but the video pipeline will block it from real production renders unless cleared or authored as original.';
                        }

                        return 'Are you sure you want to approve this component for production renders?';
                    })
                    ->visible(fn (MotionComponent $record): bool => $record->status !== MotionComponentStatus::Approved)
                    ->action(function (MotionComponent $record): void {
                        $record->update([
                            'status' => MotionComponentStatus::Approved,
                            'approved_at' => now(),
                            'approved_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Motion Component Approved')
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (MotionComponent $record): bool => $record->status !== MotionComponentStatus::Rejected)
                    ->action(function (MotionComponent $record): void {
                        $record->update([
                            'status' => MotionComponentStatus::Rejected,
                        ]);

                        Notification::make()
                            ->title('Motion Component Rejected')
                            ->danger()
                            ->send();
                    }),

                Action::make('markOriginal')
                    ->label('Mark as Original')
                    ->icon('heroicon-o-shield-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Confirm In-House Original IP')
                    ->modalDescription('Confirm this component is 100% original code authored in-house and safe for broadcast without copyright restriction.')
                    ->visible(fn (MotionComponent $record): bool => $record->license_status !== MotionLicenseStatus::Original)
                    ->action(function (MotionComponent $record): void {
                        $record->update([
                            'license_status' => MotionLicenseStatus::Original,
                        ]);

                        Notification::make()
                            ->title('License updated to Original In-House')
                            ->info()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}

