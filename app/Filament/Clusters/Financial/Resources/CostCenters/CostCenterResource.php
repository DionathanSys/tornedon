<?php

namespace App\Filament\Clusters\Financial\Resources\CostCenters;

use App\Filament\Clusters\Financial\Resources\CostCenters\Pages\CreateCostCenter;
use App\Filament\Clusters\Financial\Resources\CostCenters\Pages\EditCostCenter;
use App\Filament\Clusters\Financial\Resources\CostCenters\Pages\ListCostCenters;
use App\Filament\Clusters\Financial\Resources\CostCenters\Schemas\CostCenterForm;
use App\Filament\Clusters\Financial\Resources\CostCenters\Tables\CostCentersTable;
use App\Models\CostCenter;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CostCenterResource extends Resource
{
    protected static ?string $model = CostCenter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Centro de Custo';

    protected static ?string $pluralModelLabel = 'Centros de Custo';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return CostCenterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CostCentersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCostCenters::route('/'),
            'create' => CreateCostCenter::route('/create'),
            'edit' => EditCostCenter::route('/{record}/edit'),
        ];
    }
}
