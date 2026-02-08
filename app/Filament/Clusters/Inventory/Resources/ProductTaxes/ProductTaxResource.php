<?php

namespace App\Filament\Clusters\Inventory\Resources\ProductTaxes;

use App\Filament\Clusters\Inventory\InventoryCluster;
use App\Filament\Clusters\Inventory\Resources\ProductTaxes\Pages\CreateProductTax;
use App\Filament\Clusters\Inventory\Resources\ProductTaxes\Pages\EditProductTax;
use App\Filament\Clusters\Inventory\Resources\ProductTaxes\Pages\ListProductTaxes;
use App\Filament\Clusters\Inventory\Resources\ProductTaxes\Schemas\ProductTaxForm;
use App\Filament\Clusters\Inventory\Resources\ProductTaxes\Tables\ProductTaxesTable;
use App\Models\ProductTax;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductTaxResource extends Resource
{
    protected static ?string $model = ProductTax::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ReceiptPercent;

    protected static ?string $tenantOwnershipRelationshipName = 'product';

    protected static ?string $cluster = InventoryCluster::class;

    protected static ?string $modelLabel = 'Imposto de Produto';

    protected static ?string $pluralModelLabel = 'Impostos de Produtos';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return ProductTaxForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductTaxesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductTaxes::route('/'),
            'create' => CreateProductTax::route('/create'),
            'edit' => EditProductTax::route('/{record}/edit'),
        ];
    }
}
