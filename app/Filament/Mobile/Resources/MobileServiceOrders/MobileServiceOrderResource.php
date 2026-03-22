<?php

namespace App\Filament\Mobile\Resources\MobileServiceOrders;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Schemas\ServiceOrderForm;
use App\Filament\Mobile\Resources\MobileServiceOrders\Pages\CreateMobileServiceOrder;
use App\Filament\Mobile\Resources\MobileServiceOrders\Pages\EditMobileServiceOrder;
use App\Filament\Mobile\Resources\MobileServiceOrders\Pages\ListMobileServiceOrders;
use App\Filament\Mobile\Resources\MobileServiceOrders\Tables\MobileServiceOrdersTable;
use App\Models\ServiceOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MobileServiceOrderResource extends Resource
{
    protected static ?string $model = ServiceOrder::class;

    protected static ?string $slug = 'service-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static ?string $modelLabel = "Ordem de Servi\u{00E7}o";

    protected static ?string $pluralModelLabel = "Ordens de Servi\u{00E7}o";

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return ServiceOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MobileServiceOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMobileServiceOrders::route('/'),
            'create' => CreateMobileServiceOrder::route('/create'),
            'edit' => EditMobileServiceOrder::route('/{record}/edit'),
        ];
    }
}
