<?php

namespace App\Filament\Bpo\Resources\AccountPayables\Pages;

use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\Pages\ListFinancialTitles;

class ListAccounts extends ListFinancialTitles
{
    protected static string $resource = AccountPayableResource::class;
}
