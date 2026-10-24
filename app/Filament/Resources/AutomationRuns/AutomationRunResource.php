<?php

namespace App\Filament\Resources\AutomationRuns;

use App\Enums\AutomationRunStatus;
use App\Filament\Resources\AutomationRuns\Pages\ListAutomationRuns;
use App\Filament\Resources\AutomationRuns\Pages\ViewAutomationRun;
use App\Filament\Resources\AutomationRuns\Schemas\AutomationRunInfolist;
use App\Filament\Resources\AutomationRuns\Tables\AutomationRunsTable;
use App\Models\AutomationRun;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class AutomationRunResource extends Resource
{
    protected static ?string $model = AutomationRun::class;

    protected static ?string $modelLabel = 'Automation run';

    protected static ?string $pluralModelLabel = 'Automation runs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Automation Runs';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return SchemaFacade::hasTable((new AutomationRun)->getTable())
            && parent::canAccess();
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
        if (! SchemaFacade::hasTable((new AutomationRun)->getTable())) {
            return null;
        }

        $active = AutomationRun::query()
            ->whereIn('status', [
                AutomationRunStatus::Running->value,
                AutomationRunStatus::Paused->value,
            ])
            ->count();

        return $active > 0 ? (string) $active : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function table(Table $table): Table
    {
        return AutomationRunsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AutomationRunInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAutomationRuns::route('/'),
            'view' => ViewAutomationRun::route('/{record}'),
        ];
    }
}
