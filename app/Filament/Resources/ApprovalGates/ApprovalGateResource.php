<?php

namespace App\Filament\Resources\ApprovalGates;

use App\Enums\ApprovalGateStatus;
use App\Filament\Resources\ApprovalGates\Pages\ListApprovalGates;
use App\Filament\Resources\ApprovalGates\Pages\ViewApprovalGate;
use App\Filament\Resources\ApprovalGates\Schemas\ApprovalGateInfolist;
use App\Filament\Resources\ApprovalGates\Tables\ApprovalGatesTable;
use App\Models\ApprovalGate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class ApprovalGateResource extends Resource
{
    protected static ?string $model = ApprovalGate::class;

    protected static ?string $modelLabel = 'Approval gate';

    protected static ?string $pluralModelLabel = 'Approval gates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Approval Gates';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        return SchemaFacade::hasTable((new ApprovalGate)->getTable())
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
        if (! SchemaFacade::hasTable((new ApprovalGate)->getTable())) {
            return null;
        }

        $pending = ApprovalGate::query()
            ->where('status', ApprovalGateStatus::Pending->value)
            ->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pending approval gates';
    }

    public static function table(Table $table): Table
    {
        return ApprovalGatesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApprovalGateInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalGates::route('/'),
            'view' => ViewApprovalGate::route('/{record}'),
        ];
    }
}
