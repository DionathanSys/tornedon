<?php

namespace App\Filament\Bpo\Resources\AccountPayables\Pages;

use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\Pages\CreateFinancialTitle;

class CreateAccount extends CreateFinancialTitle
{
    protected static string $resource = AccountPayableResource::class;
}
