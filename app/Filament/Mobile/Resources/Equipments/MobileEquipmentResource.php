<?php

namespace App\Filament\Mobile\Resources\Equipments;

use App\Filament\Mobile\Resources\Equipments\Pages\CreateMobileEquipment;
use App\Filament\Mobile\Resources\Equipments\Pages\EditMobileEquipment;
use App\Filament\Mobile\Resources\Equipments\Pages\ListMobileEquipments;
use App\Filament\Mobile\Resources\Equipments\Schemas\EquipmentForm;
use App\Filament\Mobile\Resources\Equipments\Tables\MobileEquipmentsTable;
use App\Models\Equipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MobileEquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static ?string $slug = 'equipments';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Wrench;

    protected static ?string $modelLabel = 'Equipamento';

    protected static ?string $pluralModelLabel = 'Equipamentos';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return EquipmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MobileEquipmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMobileEquipments::route('/'),
            'create' => CreateMobileEquipment::route('/create'),
            'edit' => EditMobileEquipment::route('/{record}/edit'),
        ];
    }
}
