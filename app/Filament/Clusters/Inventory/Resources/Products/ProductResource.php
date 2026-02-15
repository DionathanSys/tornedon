<?php

namespace App\Filament\Clusters\Inventory\Resources\Products;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\Products\Pages\CreateProduct;
use App\Filament\Clusters\Inventory\Resources\Products\Pages\EditProduct;
use App\Filament\Clusters\Inventory\Resources\Products\Pages\ListProducts;
use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Pages\ManageProductTax;
use App\Filament\Clusters\Inventory\Resources\Products\Schemas\ProductForm;
use App\Filament\Clusters\Inventory\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cube;

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $modelLabel = 'Produto';

    protected static ?string $pluralModelLabel = 'Produtos';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
            'tax' => ManageProductTax::route('/{record}/tax'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }

}
