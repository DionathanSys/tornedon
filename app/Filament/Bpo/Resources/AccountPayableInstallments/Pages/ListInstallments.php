<?php

namespace App\Filament\Bpo\Resources\AccountPayableInstallments\Pages;

use App\Filament\Bpo\Resources\AccountPayableInstallmentResource;
use App\Filament\Bpo\Resources\Pages\ListFinancialInstallments;

class ListInstallments extends ListFinancialInstallments
{
    protected static string $resource = AccountPayableInstallmentResource::class;
}
