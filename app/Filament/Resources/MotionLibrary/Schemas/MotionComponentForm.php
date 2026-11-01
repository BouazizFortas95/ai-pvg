<?php

namespace App\Filament\Resources\MotionLibrary\Schemas;

use App\Enums\MotionCategory;
use App\Enums\MotionLicenseStatus;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MotionComponentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Component Identity')
                    ->description('Unique key, category, and contrast audit')
                    ->schema([
                        TextInput::make('component_key')
                            ->label('Component Key')
                            ->required()
                            ->disabled(fn ($record) => $record !== null),

                        Select::make('category')
                            ->label('Motion Category')
                            ->options(MotionCategory::options())
                            ->required(),

                        TextInput::make('version')
                            ->numeric()
                            ->default(1)
                            ->required(),

                        TextInput::make('contrast_rating')
                            ->label('WCAG Contrast Rating')
                            ->numeric()
                            ->default(4.5)
                            ->helperText('Minimum 4.5:1 required for accessibility compliance'),
                    ])->columns(2),

                Section::make('Licensing & Attribution')
                    ->description('Attribution must be honored. Reference-only cannot be rendered.')
                    ->schema([
                        Select::make('license_status')
                            ->label('License Status')
                            ->options([
                                MotionLicenseStatus::ReferenceOnly->value => 'Reference Only (Inspiration only — cannot render)',
                                MotionLicenseStatus::Cleared->value => 'Cleared / Licensed',
                                MotionLicenseStatus::Original->value => 'Original In-House Component',
                            ])
                            ->required(),
                    ]),

                Section::make('Code Templates')
                    ->description('Self-contained HTML and paused GSAP animation code')
                    ->schema([
                        Textarea::make('html_template')
                            ->label('HTML Fragment')
                            ->rows(6)
                            ->required(),

                        Textarea::make('gsap_script')
                            ->label('GSAP Animation Script')
                            ->rows(8)
                            ->required(),
                    ]),
            ]);
    }
}

