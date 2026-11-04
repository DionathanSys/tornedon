<?php

namespace App\Filament\Bpo\Resources\AccountReceivableInstallments\Pages;

use App\Filament\Bpo\Resources\AccountReceivableInstallmentResource;
use App\Filament\Bpo\Resources\Pages\ListFinancialInstallments;

class ListInstallments extends ListFinancialInstallments
{
    protected static string $resource = AccountReceivableInstallmentResource::class;
}
