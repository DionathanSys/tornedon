<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallments;

use App\Filament\Clusters\Financial\FinancialCluster;
use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Pages\ListAccountPayableInstallments;
use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Tables\AccountPayableInstallmentsTable;
use App\Models\AccountPayableInstallment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountPayableInstallmentResource extends Resource
{
    protected static ?string $model = AccountPayableInstallment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::QueueList;

    protected static ?string $cluster = FinancialCluster::class;

    protected static ?string $modelLabel = 'Parcela à Pagar';

    protected static ?string $pluralModelLabel = 'Parcelas à Pagar';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return AccountPayableInstallmentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with([
                'accountPayable.supplier',
                'financialCategory',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountPayableInstallments::route('/'),
        ];
    }
}
