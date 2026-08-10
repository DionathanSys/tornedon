<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCardStatements;

use App\Filament\Clusters\Financial\Resources\CompanyCardStatements\Pages\ListCompanyCardStatements;
use App\Filament\Clusters\Financial\Resources\CompanyCardStatements\Tables\CompanyCardStatementsTable;
use App\Models\CompanyCardStatement;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CompanyCardStatementResource extends Resource
{
    protected static ?string $model = CompanyCardStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Fatura de Cartão';

    protected static ?string $pluralModelLabel = 'Faturas de Cartão';

    protected static ?int $navigationSort = 11;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with(['companyCreditCard', 'accountPayable']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return CompanyCardStatementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyCardStatements::route('/'),
        ];
    }
}
