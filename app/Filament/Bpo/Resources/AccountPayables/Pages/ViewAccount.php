<?php

namespace App\Filament\Bpo\Resources\AccountPayables\Pages;

use App\Filament\Bpo\Resources\AccountPayableResource;
use App\Filament\Bpo\Resources\Pages\ViewFinancialTitle;

class ViewAccount extends ViewFinancialTitle
{
    protected static string $resource = AccountPayableResource::class;
}
