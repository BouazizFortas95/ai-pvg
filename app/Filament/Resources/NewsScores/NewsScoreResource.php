<?php

namespace App\Filament\Resources\NewsScores;

use App\Enums\TriageBand;
use App\Filament\Resources\NewsScores\Pages\ListNewsScores;
use App\Filament\Resources\NewsScores\Pages\ViewNewsScore;
use App\Filament\Resources\NewsScores\Schemas\NewsScoreInfolist;
use App\Filament\Resources\NewsScores\Tables\NewsScoresTable;
use App\Models\News;
use App\Models\NewsScore;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class NewsScoreResource extends Resource
{
    protected static ?string $model = NewsScore::class;

    protected static ?string $modelLabel = 'News score';

    protected static ?string $pluralModelLabel = 'News scores';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'News Scores';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return self::tablesAvailable() && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::tablesAvailable()) {
            return null;
        }

        $count = NewsScore::query()
            ->where('band', TriageBand::AutoGreenlight->value)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Auto-greenlight band scores';
    }

    public static function table(Table $table): Table
    {
        return NewsScoresTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return NewsScoreInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsScores::route('/'),
            'view' => ViewNewsScore::route('/{record}'),
        ];
    }

    private static function tablesAvailable(): bool
    {
        return SchemaFacade::hasTable((new NewsScore)->getTable())
            && SchemaFacade::hasTable((new News)->getTable());
    }
}
