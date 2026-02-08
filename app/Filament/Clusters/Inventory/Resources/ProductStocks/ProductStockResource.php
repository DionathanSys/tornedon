<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductStocks;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\ProductStocks\Pages\CreateProductStock;
use App\Filament\Clusters\Inventory\Resources\ProductStocks\Pages\EditProductStock;
use App\Filament\Clusters\Inventory\Resources\ProductStocks\Pages\ListProductStocks;
use App\Filament\Clusters\Inventory\Resources\ProductStocks\Schemas\ProductStockForm;
use App\Filament\Clusters\Inventory\Resources\ProductStocks\Tables\ProductStocksTable;
use App\Models\ProductStock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductStockResource extends Resource
{
    protected static ?string $model = ProductStock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $modelLabel = 'Estoque';

    protected static ?string $pluralModelLabel = 'Estoques';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ProductStockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductStocksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductStocks::route('/'),
            'create' => CreateProductStock::route('/create'),
            'edit' => EditProductStock::route('/{record}/edit'),
        ];
    }
}
