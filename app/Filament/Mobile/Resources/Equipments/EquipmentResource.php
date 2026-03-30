<?php

namespace App\Filament\Mobile\Resources\Equipments;

use App\Filament\Mobile\Resources\Equipments\Pages\CreateEquipment;
use App\Filament\Mobile\Resources\Equipments\Pages\EditEquipment;
use App\Filament\Mobile\Resources\Equipments\Pages\ListEquipment;
use App\Filament\Mobile\Resources\Equipments\Schemas\EquipmentsForm;
use App\Filament\Mobile\Resources\Equipments\Tables\EquipmentsTable;
use App\Models\Equipment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class EquipmentResource extends Resource
{
    protected static ?string $model = Equipment::class;

    protected static ?string $slug = 'equipamentos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Wrench;

    protected static ?string $modelLabel = 'Equipamento';

    protected static ?string $pluralModelLabel = 'Equipamentos';

    protected static ?string $recordTitleAttribute = 'identifier';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return EquipmentsForm::configure($schema);
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
            'index' => ListEquipment::route('/'),
            'create' => CreateEquipment::route('/create'),
            'edit' => EditEquipment::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
