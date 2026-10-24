<?php

namespace App\Filament\Resources\ScriptVariants;

use App\Enums\ScriptVariantType;
use App\Filament\Resources\ScriptVariants\Pages\ListScriptVariants;
use App\Filament\Resources\ScriptVariants\Pages\ViewScriptVariant;
use App\Filament\Resources\ScriptVariants\Schemas\ScriptVariantInfolist;
use App\Filament\Resources\ScriptVariants\Tables\ScriptVariantsTable;
use App\Models\ScriptVariant;
use App\Models\VideoScript;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class ScriptVariantResource extends Resource
{
    protected static ?string $model = ScriptVariant::class;

    protected static ?string $modelLabel = 'Script variant';

    protected static ?string $pluralModelLabel = 'Script variants';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Script Variants';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'variant_key';

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

        $count = ScriptVariant::query()
            ->where('variant_type', ScriptVariantType::Hook->value)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Grounded hook variants awaiting review';
    }

    public static function table(Table $table): Table
    {
        return ScriptVariantsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ScriptVariantInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListScriptVariants::route('/'),
            'view' => ViewScriptVariant::route('/{record}'),
        ];
    }

    private static function tablesAvailable(): bool
    {
        return SchemaFacade::hasTable((new ScriptVariant)->getTable())
            && SchemaFacade::hasTable((new VideoScript)->getTable());
    }
}
