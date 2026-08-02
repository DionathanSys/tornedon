<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallmentPayments;

use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallmentPayments\Pages\ListAccountReceivableInstallmentPayments;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallmentPayments\Tables\AccountReceivableInstallmentPaymentsTable;
use App\Models\AccountReceivableInstallmentPayment;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AccountReceivableInstallmentPaymentResource extends Resource
{
    protected static ?string $model = AccountReceivableInstallmentPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static bool $shouldRegisterNavigation = false;

    protected static string | UnitEnum | null $navigationGroup = 'Financeiro';

    protected static ?string $modelLabel = 'Pgto de parcela à receber';

    protected static ?string $pluralModelLabel = 'Pgtos de parcelas à receber';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return AccountReceivableInstallmentPaymentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('company_id', Filament::getTenant()->id)
            ->with([
                'installment.accountReceivable.customer',
                'financialAccount',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountReceivableInstallmentPayments::route('/'),
        ];
    }
}
