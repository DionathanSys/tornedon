<?php

namespace App\Filament\Clusters\Financial\Resources\AccountPayables\Pages;

use App\Filament\Clusters\Financial\Concerns\HasFinancialTabs;
use App\Filament\Clusters\Financial\Resources\AccountPayables\AccountPayableResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountPayables extends ListRecords
{
    use HasFinancialTabs;

    protected static string $resource = AccountPayableResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
