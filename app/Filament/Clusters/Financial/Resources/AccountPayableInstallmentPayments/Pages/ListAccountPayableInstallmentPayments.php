<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallmentPayments\Pages;

use App\Filament\Clusters\Financial\Resources\AccountPayableInstallmentPayments\AccountPayableInstallmentPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountPayableInstallmentPayments extends ListRecords
{
    protected static string $resource = AccountPayableInstallmentPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
