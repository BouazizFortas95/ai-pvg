<?php

namespace App\Filament\Resources\NewsSources\Schemas;

use App\Models\NewsSource;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class NewsSourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Source Information'))
                    ->description(__('Identity of the external publisher that articles come from.'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Source name'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(NewsSource::class, 'slug', ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),

                        TextInput::make('website_url')
                            ->label(__('Website URL'))
                            ->nullable()
                            ->url()
                            ->maxLength(65535),

                        TextInput::make('rss_url')
                            ->label(__('RSS URL'))
                            ->nullable()
                            ->url()
                            ->maxLength(65535),
                    ])
                    ->columns(2),

                Section::make(__('Details'))
                    ->description(__('Branding, language, and activity settings.'))
                    ->schema([
                        Textarea::make('description')
                            ->label(__('Description'))
                            ->nullable()
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),

                        FileUpload::make('logo')
                            ->label(__('Logo'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('news-sources/logos')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'])
                            ->maxSize(2048)
                            ->nullable(),

                        Select::make('language')
                            ->label(__('Language'))
                            ->required()
                            ->native(false)
                            ->default('en')
                            ->options(NewsSource::LANGUAGES),

                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->default(true),

                        DatePicker::make('last_fetched_at')
                            ->label(__('Last fetched date'))
                            ->hint(__('Set automatically once RSS fetching is enabled.')),
                    ])
                    ->columns(2),
            ]);
    }
}
