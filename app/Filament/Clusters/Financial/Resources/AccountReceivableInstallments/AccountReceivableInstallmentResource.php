<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Pages\ListAccountReceivableInstallments;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Tables\AccountReceivableInstallmentsTable;
use App\Models\AccountReceivableInstallment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountReceivableInstallmentResource extends Resource
{
    protected static ?string $model = AccountReceivableInstallment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::QueueList;

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'Parcela a Receber';

    protected static ?string $pluralModelLabel = 'Parcelas a Receber';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return AccountReceivableInstallmentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with([
                'accountReceivable.customer',
                'financialCategory',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountReceivableInstallments::route('/'),
        ];
    }
}
