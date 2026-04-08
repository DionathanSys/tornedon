<?php

namespace App\Filament\Clusters\Financial\Resources\FinancialAccounts;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\FinancialAccounts\Pages\CreateFinancialAccount;
use App\Filament\Clusters\Financial\Resources\FinancialAccounts\Pages\EditFinancialAccount;
use App\Filament\Clusters\Financial\Resources\FinancialAccounts\Pages\ListFinancialAccounts;
use App\Filament\Clusters\Financial\Resources\FinancialAccounts\Schemas\FinancialAccountForm;
use App\Filament\Clusters\Financial\Resources\FinancialAccounts\Tables\FinancialAccountsTable;
use App\Models\FinancialAccount;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FinancialAccountResource extends Resource
{
    protected static ?string $model = FinancialAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingLibrary;

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'Conta Financeira';

    protected static ?string $pluralModelLabel = 'Contas Financeiras';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return FinancialAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinancialAccountsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialAccounts::route('/'),
            // 'create' => CreateFinancialAccount::route('/create'),
            // 'edit' => EditFinancialAccount::route('/{record}/edit'),
        ];
    }
}
