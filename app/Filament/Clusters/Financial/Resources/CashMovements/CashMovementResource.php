<?php

namespace App\Filament\Clusters\Financial\Resources\CashMovements;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\CreateCashMovement;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\EditCashMovement;
use App\Filament\Clusters\Financial\Resources\CashMovements\Pages\ListCashMovements;
use App\Filament\Clusters\Financial\Resources\CashMovements\Schemas\CashMovementForm;
use App\Filament\Clusters\Financial\Resources\CashMovements\Tables\CashMovementsTable;
use App\Models\CashMovement;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;
use Illuminate\Database\Eloquent\Builder;

class CashMovementResource extends Resource
{
    protected static ?string $model = CashMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowsRightLeft;

    // protected static ?string $cluster = FinancialCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Movimento Financeiro';

    protected static ?string $pluralModelLabel = 'Extrato Financeiro';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return CashMovementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CashMovementsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashMovements::route('/'),
            'create' => CreateCashMovement::route('/create'),
            'edit' => EditCashMovement::route('/{record}/edit'),
        ];
    }
}
