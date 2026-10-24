<?php

namespace App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages;

use App\Filament\Clusters\Financial\Concerns\HasFinancialTabs;
use App\Filament\Clusters\Financial\Resources\AccountReceivables\AccountReceivableResource;
use Filament\Resources\Pages\ListRecords;

class ListAccountReceivables extends ListRecords
{
    use HasFinancialTabs;

    protected static string $resource = AccountReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [
        ];
    }
}
