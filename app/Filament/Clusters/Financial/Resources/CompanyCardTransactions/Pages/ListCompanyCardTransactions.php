<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCardTransactions\Pages;

use App\Filament\Clusters\Financial\Resources\CompanyCardTransactions\CompanyCardTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListCompanyCardTransactions extends ListRecords
{
    protected static string $resource = CompanyCardTransactionResource::class;
}
