<?php

namespace App\Filament\Clusters\Inventory\Resources\StockMovements;

use App\Filament\Clusters\Inventory\Resources\StockMovements\Pages\CreateStockMovement;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Pages\EditStockMovement;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Clusters\Inventory\Resources\StockMovements\Tables\StockMovementsTable;
use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowsRightLeft;

    // protected static ?string $cluster = InventoryCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Estoque';

    protected static ?string $modelLabel = 'Movimentação de Estoque';

    protected static ?string $pluralModelLabel = 'Movimentações de Estoque';

    protected static ?int $navigationSort = 4;

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
            'create' => CreateStockMovement::route('/create'),
            'edit' => EditStockMovement::route('/{record}/edit'),
        ];
    }
}
