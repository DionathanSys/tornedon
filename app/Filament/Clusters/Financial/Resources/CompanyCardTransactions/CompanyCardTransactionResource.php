<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCardTransactions;

use App\Filament\Clusters\Financial\Resources\CompanyCardTransactions\Pages\ListCompanyCardTransactions;
use App\Filament\Clusters\Financial\Resources\CompanyCardTransactions\Tables\CompanyCardTransactionsTable;
use App\Models\CompanyCardTransaction;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class CompanyCardTransactionResource extends Resource
{
    protected static ?string $model = CompanyCardTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Transação de Cartão';

    protected static ?string $pluralModelLabel = 'Transações de Cartão';

    protected static ?int $navigationSort = 12;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with(['companyCreditCard', 'vendor']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return CompanyCardTransactionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanyCardTransactions::route('/'),
        ];
    }
}
