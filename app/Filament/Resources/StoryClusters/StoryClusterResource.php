<?php

namespace App\Filament\Resources\StoryClusters;

use App\Enums\StoryClusterStatus;
use App\Filament\Resources\StoryClusters\Pages\ListStoryClusters;
use App\Filament\Resources\StoryClusters\Pages\ViewStoryCluster;
use App\Filament\Resources\StoryClusters\Schemas\StoryClusterInfolist;
use App\Filament\Resources\StoryClusters\Tables\StoryClustersTable;
use App\Models\News;
use App\Models\StoryCluster;
use App\Models\StoryClusterNews;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class StoryClusterResource extends Resource
{
    protected static ?string $model = StoryCluster::class;

    protected static ?string $modelLabel = 'Story cluster';

    protected static ?string $pluralModelLabel = 'Story clusters';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CircleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Story Clusters';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'canonical_title';

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

        $count = StoryCluster::query()
            ->whereIn('status', [
                StoryClusterStatus::Open->value,
                StoryClusterStatus::Ready->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function table(Table $table): Table
    {
        return StoryClustersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return StoryClusterInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStoryClusters::route('/'),
            'view' => ViewStoryCluster::route('/{record}'),
        ];
    }

    private static function tablesAvailable(): bool
    {
        return SchemaFacade::hasTable((new StoryCluster)->getTable())
            && SchemaFacade::hasTable((new StoryClusterNews)->getTable())
            && SchemaFacade::hasTable((new News)->getTable());
    }
}
