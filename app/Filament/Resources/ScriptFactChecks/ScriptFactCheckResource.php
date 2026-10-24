<?php

namespace App\Filament\Resources\ScriptFactChecks;

use App\Enums\ScriptFactCheckVerdict;
use App\Filament\Resources\ScriptFactChecks\Pages\ListScriptFactChecks;
use App\Filament\Resources\ScriptFactChecks\Pages\ViewScriptFactCheck;
use App\Filament\Resources\ScriptFactChecks\Schemas\ScriptFactCheckInfolist;
use App\Filament\Resources\ScriptFactChecks\Tables\ScriptFactChecksTable;
use App\Models\ScriptFactCheck;
use App\Models\VideoScript;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class ScriptFactCheckResource extends Resource
{
    protected static ?string $model = ScriptFactCheck::class;

    protected static ?string $modelLabel = 'Script fact check';

    protected static ?string $pluralModelLabel = 'Script fact checks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Script Fact Checks';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'claim';

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

        $count = ScriptFactCheck::query()
            ->whereIn('verdict', [
                ScriptFactCheckVerdict::Unsupported->value,
                ScriptFactCheckVerdict::Uncertain->value,
            ])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Claims blocking automatic script approval';
    }

    public static function table(Table $table): Table
    {
        return ScriptFactChecksTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ScriptFactCheckInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScriptFactChecks::route('/'),
            'view' => ViewScriptFactCheck::route('/{record}'),
        ];
    }

    private static function tablesAvailable(): bool
    {
        return SchemaFacade::hasTable((new ScriptFactCheck)->getTable())
            && SchemaFacade::hasTable((new VideoScript)->getTable());
    }
}
