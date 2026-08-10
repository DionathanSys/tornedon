<?php

namespace App\Filament\Clusters\Financial\Resources\ResultCenters;

use App\Filament\Clusters\Financial\Resources\ResultCenters\Pages\CreateResultCenter;
use App\Filament\Clusters\Financial\Resources\ResultCenters\Pages\EditResultCenter;
use App\Filament\Clusters\Financial\Resources\ResultCenters\Pages\ListResultCenters;
use App\Filament\Clusters\Financial\Resources\ResultCenters\Schemas\ResultCenterForm;
use App\Filament\Clusters\Financial\Resources\ResultCenters\Tables\ResultCentersTable;
use App\Models\ResultCenter;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ResultCenterResource extends Resource
{
    protected static ?string $model = ResultCenter::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Centro de Resultado';

    protected static ?string $pluralModelLabel = 'Centros de Resultado';

    protected static ?int $navigationSort = 13;

    public static function form(Schema $schema): Schema
    {
        return ResultCenterForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResultCentersTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResultCenters::route('/'),
            'create' => CreateResultCenter::route('/create'),
            'edit' => EditResultCenter::route('/{record}/edit'),
        ];
    }
}
