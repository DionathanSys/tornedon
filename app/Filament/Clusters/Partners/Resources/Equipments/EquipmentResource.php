<?php

namespace App\Filament\Clusters\Partners\Resources\Equipments;

use App\Filament\Clusters\Partners\PartnersCluster;
use App\Filament\Clusters\Partners\Resources\Equipments\Pages\CreateEquipment;
use App\Filament\Clusters\Partners\Resources\Equipments\Pages\EditEquipment;
use App\Filament\Clusters\Partners\Resources\Equipments\Pages\ListEquipments;
use App\Filament\Clusters\Partners\Resources\Equipments\Schemas\EquipmentForm;
use App\Filament\Clusters\Partners\Resources\Equipments\Tables\EquipmentsTable;
use App\Models\Equipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Wrench;

    protected static ?string $cluster = PartnersCluster::class;

    protected static ?string $modelLabel = 'Equipamento';

    protected static ?string $pluralModelLabel = 'Equipamentos';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return EquipmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EquipmentsTable::configure($table);
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
            'index' => ListEquipments::route('/'),
            'create' => CreateEquipment::route('/create'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }
}
