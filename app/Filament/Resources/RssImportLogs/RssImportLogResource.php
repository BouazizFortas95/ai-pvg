<?php

namespace App\Filament\Resources\RssImportLogs;

use App\Filament\Resources\RssImportLogs\Pages\ListRssImportLogs;
use App\Filament\Resources\RssImportLogs\Tables\RssImportLogsTable;
use App\Models\RssImportLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RssImportLogResource extends Resource
{
    protected static ?string $model = RssImportLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Content Management';

    protected static ?string $navigationLabel = 'Import Logs';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'id';

    public static function table(Table $table): Table
    {
        return RssImportLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRssImportLogs::route('/'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getModelLabel(): string
    {
        return 'RSS Import Log';
    }
}
