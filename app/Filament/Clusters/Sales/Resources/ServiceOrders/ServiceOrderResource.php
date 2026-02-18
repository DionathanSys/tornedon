<?php

namespace App\Filament\Clusters\Sales\Resources\ServiceOrders;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\CreateServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\ListServiceOrders;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Filament\Clusters\Sales\Resources\ServiceOrders\Tables\ServiceOrdersTable;
use App\Filament\Clusters\Sales\SalesCluster;
use App\Models\ServiceOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ServiceOrderResource extends Resource
{
    protected static ?string $model = ServiceOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static ?string $cluster = SalesCluster::class;

    protected static ?string $modelLabel = 'Ordem de Serviço';

    protected static ?string $pluralModelLabel = 'Ordens de Serviço';

    protected static ?int $navigationSort = 4;

    public static function schema(Schema $schema): Schema
    {
        return ServiceOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceOrders::route('/'),
            'create' => CreateServiceOrder::route('/create'),
            'edit' => EditServiceOrder::route('/{record}/edit'),
        ];
    }
}
