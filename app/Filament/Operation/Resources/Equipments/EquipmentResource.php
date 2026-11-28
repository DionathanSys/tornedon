<?php

namespace App\Filament\Operation\Resources\Equipments;

use App\Filament\Clusters\Partners\Resources\Equipments\Schemas\EquipmentForm;
use App\Filament\Operation\Concerns\AuthorizesOperationResource;
use App\Filament\Operation\Resources\Equipments\Pages\CreateEquipment;
use App\Filament\Operation\Resources\Equipments\Pages\EditEquipment;
use App\Filament\Operation\Resources\Equipments\Pages\ListEquipments;
use App\Filament\Operation\Resources\Equipments\Tables\EquipmentsTable;
use App\Models\Equipment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class EquipmentResource extends Resource
{
    use AuthorizesOperationResource;

    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Wrench;

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?string $modelLabel = 'Equipamento';

    protected static ?string $pluralModelLabel = 'Equipamentos';

    public static function form(Schema $schema): Schema
    {
        return EquipmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EquipmentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
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
