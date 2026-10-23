<?php

namespace App\Filament\Resources\NewsCategories\Schemas;

use App\Models\NewsCategory;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class NewsCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Category Information'))
                    ->description(__('Identity and styling for the article category.'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(NewsCategory::class, 'slug', ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),

                        Textarea::make('description')
                            ->label(__('Description'))
                            ->nullable()
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),

                        ColorPicker::make('color')
                            ->label(__('Color'))
                            ->nullable(),

                        TextInput::make('icon')
                            ->label(__('Icon'))
                            ->nullable()
                            ->maxLength(255)

                            ->placeholder('heroicon-o-tag'),

                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
