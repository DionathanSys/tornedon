<?php

namespace App\Filament\Clusters\Financial\Resources\ChartAccounts;

use App\Filament\Clusters\Financial\Resources\ChartAccounts\Pages\CreateChartAccount;
use App\Filament\Clusters\Financial\Resources\ChartAccounts\Pages\EditChartAccount;
use App\Filament\Clusters\Financial\Resources\ChartAccounts\Pages\ListChartAccounts;
use App\Filament\Clusters\Financial\Resources\ChartAccounts\Schemas\ChartAccountForm;
use App\Filament\Clusters\Financial\Resources\ChartAccounts\Tables\ChartAccountsTable;
use App\Models\ChartAccount;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ChartAccountResource extends Resource
{
    protected static ?string $model = ChartAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Plano de Contas';

    protected static ?string $pluralModelLabel = 'Plano de Contas';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return ChartAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChartAccountsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChartAccounts::route('/'),
            'create' => CreateChartAccount::route('/create'),
            'edit' => EditChartAccount::route('/{record}/edit'),
        ];
    }
}
