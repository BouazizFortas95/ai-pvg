<?php

namespace App\Filament\Resources\VideoScripts;

use App\Filament\Resources\VideoScripts\Pages\ListVideoScripts;
use App\Filament\Resources\VideoScripts\Pages\EditVideoScript;
use App\Filament\Resources\VideoScripts\Tables\VideoScriptsTable;
use App\Models\VideoScript;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class VideoScriptResource extends Resource
{
    protected static ?string $model = VideoScript::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::VideoCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Video Production';

    protected static ?string $navigationLabel = 'Video Scripts';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'title';

    public static function table(Table $table): Table
    {
        return VideoScriptsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVideoScripts::route('/'),
            'edit' => EditVideoScript::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }
}
