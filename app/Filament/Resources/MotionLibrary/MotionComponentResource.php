<?php

namespace App\Filament\Resources\MotionLibrary;

use App\Filament\Resources\MotionLibrary\Pages\CreateMotionComponent;
use App\Filament\Resources\MotionLibrary\Pages\EditMotionComponent;
use App\Filament\Resources\MotionLibrary\Pages\ListMotionComponents;
use App\Filament\Resources\MotionLibrary\Schemas\MotionComponentForm;
use App\Filament\Resources\MotionLibrary\Tables\MotionComponentsTable;
use App\Models\MotionComponent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MotionComponentResource extends Resource
{
    protected static ?string $model = MotionComponent::class;

    protected static ?string $modelLabel = 'Motion Component';

    protected static ?string $pluralModelLabel = 'Motion Library';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Video Production';

    protected static ?string $navigationLabel = 'Motion Library';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'component_key';

    public static function form(Schema $schema): Schema
    {
        return MotionComponentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MotionComponentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMotionComponents::route('/'),
            'create' => CreateMotionComponent::route('/create'),
            'edit' => EditMotionComponent::route('/{record}/edit'),
        ];
    }
}

