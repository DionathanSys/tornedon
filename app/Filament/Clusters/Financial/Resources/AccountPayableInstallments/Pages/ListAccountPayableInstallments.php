<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Pages;

use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\AccountPayableInstallmentResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountPayableInstallments extends ListRecords
{
    protected static string $resource = AccountPayableInstallmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
