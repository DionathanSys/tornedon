<?php

namespace App\Filament\Operation\Resources\CashMovements;

use App\Filament\Operation\Concerns\AuthorizesOperationResource;
use App\Filament\Operation\Resources\CashMovements\Pages\CreateCashMovement;
use App\Filament\Operation\Resources\CashMovements\Pages\EditCashMovement;
use App\Filament\Operation\Resources\CashMovements\Pages\ListCashMovements;
use App\Filament\Operation\Resources\CashMovements\Schemas\CashMovementForm;
use App\Filament\Operation\Resources\CashMovements\Tables\CashMovementsTable;
use App\Models\CashMovement;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CashMovementResource extends Resource
{
    use AuthorizesOperationResource;

    protected static ?string $model = CashMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Movimento Financeiro';

    protected static ?string $pluralModelLabel = 'Extrato Financeiro';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return CashMovementForm::configure($schema, useSections: false);
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
