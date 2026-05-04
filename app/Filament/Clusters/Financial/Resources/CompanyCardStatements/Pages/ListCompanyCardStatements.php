<?php

namespace App\Filament\Clusters\Financial\Resources\CompanyCardStatements\Pages;

use App\Filament\Clusters\Financial\Resources\CompanyCardStatements\CompanyCardStatementResource;
use Filament\Resources\Pages\ListRecords;

class ListCompanyCardStatements extends ListRecords
{
    protected static string $resource = CompanyCardStatementResource::class;
}
