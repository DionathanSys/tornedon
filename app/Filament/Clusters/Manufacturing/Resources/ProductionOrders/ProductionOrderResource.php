<?php

namespace App\Filament\Clusters\Manufacturing\Resources\ProductionOrders;

use App\Filament\Clusters\Manufacturing\ManufacturingCluster;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\CreateProductionOrder;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\EditProductionOrder;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Pages\ListProductionOrders;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\RelationManagers\ItemsRelationManager;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Schemas\ProductionOrderForm;
use App\Filament\Clusters\Manufacturing\Resources\ProductionOrders\Tables\ProductionOrdersTable;
use App\Filament\RelationManagers\AttachmentsRelationManager;
use App\Models\ProductionOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProductionOrderResource extends Resource
{
    protected static ?string $model = ProductionOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Cog;

    protected static ?string $cluster = ManufacturingCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Vendas';

    protected static ?string $modelLabel = 'Ordem de Produção';

    protected static ?string $pluralModelLabel = 'Ordens de Produção';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ProductionOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            AttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionOrders::route('/'),
            'create' => CreateProductionOrder::route('/create'),
            'edit' => EditProductionOrder::route('/{record}/edit'),
        ];
    }
}
