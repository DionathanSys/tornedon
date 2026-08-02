<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallmentPayments;

use App\Filament\Clusters\Financial\Resources\AccountPayableInstallmentPayments\Pages\ListAccountPayableInstallmentPayments;
use App\Filament\Clusters\Financial\Resources\AccountPayableInstallmentPayments\Tables\AccountPayableInstallmentPaymentsTable;
use App\Models\AccountPayableInstallmentPayment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountPayableInstallmentPaymentResource extends Resource
{
    protected static ?string $model = AccountPayableInstallmentPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static bool $shouldRegisterNavigation = false;

    protected static string | UnitEnum | null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Pgto parcela à pagar';

    protected static ?string $pluralModelLabel = 'Pgtos parcelas à pagar';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return AccountPayableInstallmentPaymentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with([
                'installment.accountPayable.supplier',
                'financialAccount',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountPayableInstallmentPayments::route('/'),
        ];
    }
}
