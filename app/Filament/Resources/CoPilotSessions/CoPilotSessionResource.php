<?php

namespace App\Filament\Resources\CoPilotSessions;

use App\Filament\Resources\CoPilotSessions\Pages\ListCoPilotSessions;
use App\Filament\Resources\CoPilotSessions\Pages\ViewCoPilotSession;
use App\Filament\Resources\CoPilotSessions\Schemas\CoPilotSessionInfolist;
use App\Filament\Resources\CoPilotSessions\Tables\CoPilotSessionsTable;
use App\Models\CoPilotReviewSession;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class CoPilotSessionResource extends Resource
{
    protected static ?string $model = CoPilotReviewSession::class;

    protected static ?string $modelLabel = 'Co-Pilot review';

    protected static ?string $pluralModelLabel = 'Co-Pilot reviews';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Publishing';

    protected static ?string $navigationLabel = 'Co-Pilot Reviews';

    protected static ?int $navigationSort = 2;

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

        $count = CoPilotReviewSession::query()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Admin-only Co-Pilot review records';
    }

    public static function table(Table $table): Table
    {
        return CoPilotSessionsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CoPilotSessionInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoPilotSessions::route('/'),
            'view' => ViewCoPilotSession::route('/{record}'),
        ];
    }

    private static function tablesAvailable(): bool
    {
        return SchemaFacade::hasTable((new CoPilotReviewSession)->getTable())
            && SchemaFacade::hasTable((new User)->getTable());
    }
}
