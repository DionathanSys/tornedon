<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\AccountPayableInstallments\Pages\ListInstallments;
use App\Models\AccountPayableInstallment;

class AccountPayableInstallmentResource extends FinancialInstallmentResource
{
    protected static ?string $model = AccountPayableInstallment::class;

    public static string $module = 'account_payables';

    protected static ?string $modelLabel = 'conta a pagar';

    protected static ?string $pluralModelLabel = 'Contas a Pagar';

    public static function getPages(): array
    {
        return ['index' => ListInstallments::route('/')];
    }
}
