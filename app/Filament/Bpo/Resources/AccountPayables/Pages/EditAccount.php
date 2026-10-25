<?php

namespace App\Filament\Bpo\Resources\AccountPayables\Pages;

use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\Pages\EditFinancialTitle;

class EditAccount extends EditFinancialTitle
{
    protected static string $resource = AccountPayableResource::class;
}
