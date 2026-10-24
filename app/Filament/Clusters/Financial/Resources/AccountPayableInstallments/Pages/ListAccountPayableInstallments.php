<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\Pages;

use App\Filament\Clusters\Financial\Concerns\HasFinancialTabs;
use App\Filament\Clusters\Financial\Resources\AccountPayableInstallments\AccountPayableInstallmentResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountPayableInstallments extends ListRecords
{
    use HasFinancialTabs;

    protected static string $resource = AccountPayableInstallmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
