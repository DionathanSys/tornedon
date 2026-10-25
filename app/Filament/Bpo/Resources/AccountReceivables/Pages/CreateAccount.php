<?php

namespace App\Filament\Bpo\Resources\AccountReceivables\Pages;

use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Filament\Bpo\Resources\Pages\CreateFinancialTitle;

class CreateAccount extends CreateFinancialTitle
{
    protected static string $resource = AccountReceivableResource::class;
}
