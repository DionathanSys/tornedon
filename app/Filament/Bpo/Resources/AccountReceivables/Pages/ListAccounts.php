<?php

namespace App\Filament\Bpo\Resources\AccountReceivables\Pages;

use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Filament\Bpo\Resources\Pages\ListFinancialTitles;

class ListAccounts extends ListFinancialTitles
{
    protected static string $resource = AccountReceivableResource::class;
}
