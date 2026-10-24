<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\Pages;

use App\Filament\Clusters\Financial\Concerns\HasFinancialTabs;
use App\Filament\Clusters\Financial\Resources\AccountReceivableInstallments\AccountReceivableInstallmentResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountReceivableInstallments extends ListRecords
{
    use HasFinancialTabs;

    protected static string $resource = AccountReceivableInstallmentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
