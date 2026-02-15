<?php

namespace App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes;

use App\Filament\Clusters\Inventory\Resources\Products\ProductResource;
use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Pages\CreateProductTax;
use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Pages\EditProductTax;
use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Pages\ViewProductTax;
use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Schemas\ProductTaxForm;
use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Schemas\ProductTaxInfolist;
use App\Filament\Clusters\Inventory\Resources\Products\Resources\ProductTaxes\Tables\ProductTaxesTable;
use App\Models\ProductTax;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductTaxResource extends Resource
{
    protected static ?string $model = ProductTax::class;

    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $parentResource = ProductResource::class;

    public static function form(Schema $schema): Schema
    {
        return ProductTaxForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductTaxInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductTaxesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'create' => CreateProductTax::route('/create'),
            'view' => ViewProductTax::route('/{record}'),
            'edit' => EditProductTax::route('/{record}/edit'),
        ];
    }
}
