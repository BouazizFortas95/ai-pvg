<?php

namespace App\Filament\Resources\News\Schemas;

use App\Enums\NewsStatus;
use App\Models\News;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class NewsForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Basic Information'))
                    ->description(__('The essential details that identify the article.'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('Title'))
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),

                        TextInput::make('slug')
                            ->label(__('Slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(News::class, 'slug', ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),

                        Select::make('category')
                            ->label(__('Category'))
                            ->required()
                            ->default('technology')
                            ->options([
                                'technology' => 'Technology',
                                'business' => 'Business',
                                'science' => 'Science',
                                'health' => 'Health',
                                'sports' => 'Sports',
                                'entertainment' => 'Entertainment',
                            ]),

                        Select::make('status')
                            ->label(__('Status'))
                            ->required()
                            ->native(false)
                            ->default(NewsStatus::Draft)
                            ->options(NewsStatus::class),
                    ])
                    ->columns(2),

                Section::make(__('Article Content'))
                    ->description(__('The written body of the article.'))
                    ->schema([
                        Textarea::make('summary')
                            ->label(__('Summary'))
                            ->nullable()
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),

                        RichEditor::make('content')
                            ->label(__('Content'))
                            ->nullable()
                            ->columnSpanFull()
                            ->toolbarButtons([
                                'bold',
                                'italic',
                                'underline',
                                'strike',
                                'link',
                                'h1',
                                'h2',
                                'h3',
                                'bulletList',
                                'orderedList',
                                'blockquote',
                                'undo',
                                'redo',
                            ]),
                    ]),

                Section::make(__('Source Information'))
                    ->description(__('References, media, and publication metadata.'))
                    ->schema([
                        TextInput::make('source_name')
                            ->label(__('Source name'))
                            ->nullable()
                            ->maxLength(255),

                        TextInput::make('source_url')
                            ->label(__('Source URL'))
                            ->nullable()
                            ->url()
                            ->maxLength(65535),

                        FileUpload::make('image')
                            ->label(__('Article image'))
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('news/images')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                            ->maxSize(5120)
                            ->nullable(),

                        DatePicker::make('published_at')
                            ->label(__('Publication date')),
                    ])
                    ->columns(2),
            ]);
    }
}
