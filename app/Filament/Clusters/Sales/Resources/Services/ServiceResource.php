<?php

namespace App\Filament\Clusters\Sales\Resources\Services;

use App\Filament\Clusters\Sales\Resources\Services\Pages\CreateService;
use App\Filament\Clusters\Sales\Resources\Services\Pages\EditService;
use App\Filament\Clusters\Sales\Resources\Services\Pages\ListServices;
use App\Filament\Clusters\Sales\Resources\Services\Schemas\ServiceForm;
use App\Filament\Clusters\Sales\Resources\Services\Tables\ServicesTable;
use App\Filament\Clusters\Sales\SalesCluster;
use App\Models\Service;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Wrench;

    // protected static ?string $cluster = SalesCluster::class;

    protected static string | UnitEnum | null $navigationGroup = 'Vendas';

    protected static ?string $modelLabel = 'Serviço';

    protected static ?string $pluralModelLabel = 'Serviços';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [];
    }
}
