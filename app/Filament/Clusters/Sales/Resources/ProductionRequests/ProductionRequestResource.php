<?php

namespace App\Filament\Clusters\Sales\Resources\ProductionRequests;

use App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\CreateProductionRequest;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\EditProductionRequest;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Pages\ListProductionRequests;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\RelationManagers\ItemsRelationManager;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Schemas\ProductionRequestForm;
use App\Filament\Clusters\Sales\Resources\ProductionRequests\Tables\ProductionRequestsTable;
use App\Models\ProductionRequest;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProductionRequestResource extends Resource
{
    protected static ?string $model = ProductionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocument;

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?string $modelLabel = 'Pedido para Produção';

    protected static ?string $pluralModelLabel = 'Pedidos para Produção';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return ProductionRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductionRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductionRequests::route('/'),
            'create' => CreateProductionRequest::route('/create'),
            'edit' => EditProductionRequest::route('/{record}/edit'),
        ];
    }
}
