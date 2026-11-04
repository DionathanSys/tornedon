<?php

namespace App\Filament\Bpo\Resources;

use App\Filament\Bpo\Resources\AccountReceivableInstallments\Pages\ListInstallments;
use App\Models\AccountReceivableInstallment;

class AccountReceivableInstallmentResource extends FinancialInstallmentResource
{
    protected static ?string $model = AccountReceivableInstallment::class;

    public static string $module = 'account_receivables';

    protected static ?string $modelLabel = 'conta a receber';

    protected static ?string $pluralModelLabel = 'Contas a Receber';

    public static function getPages(): array
    {
        return ['index' => ListInstallments::route('/')];
    }
}
