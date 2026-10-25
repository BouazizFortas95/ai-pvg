<?php

namespace App\Filament\Resources\PublishingStates;

use App\Filament\Resources\PublishingStates\Pages\ListPublishingStates;
use App\Filament\Resources\PublishingStates\Pages\ViewPublishingState;
use App\Filament\Resources\PublishingStates\Schemas\PublishingStateInfolist;
use App\Filament\Resources\PublishingStates\Tables\PublishingStatesTable;
use App\Models\PublishingApprovalSession;
use App\Models\VideoScript;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class PublishingStateResource extends Resource
{
    protected static ?string $model = PublishingApprovalSession::class;

    protected static ?string $modelLabel = 'publishing approval';

    protected static ?string $pluralModelLabel = 'publishing approvals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::PaperAirplane;

    protected static string|UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $navigationLabel = 'Publishing Approvals';

    protected static ?int $navigationSort = 1;

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

        $count = PublishingApprovalSession::query()
            ->where('status', PublishingApprovalSession::STATUS_PENDING)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Publishing approvals awaiting dual-admin review';
    }

    public static function table(Table $table): Table
    {
        return PublishingStatesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PublishingStateInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPublishingStates::route('/'),
            'view' => ViewPublishingState::route('/{record}'),
        ];
    }

    private static function tablesAvailable(): bool
    {
        return SchemaFacade::hasTable((new PublishingApprovalSession)->getTable())
            && SchemaFacade::hasTable((new VideoScript)->getTable());
    }
}
