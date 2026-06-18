<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallmentPayments\Pages;

use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallmentPayments\AccountReceivableInstallmentPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountReceivableInstallmentPayments extends ListRecords
{
    protected static string $resource = AccountReceivableInstallmentPaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
