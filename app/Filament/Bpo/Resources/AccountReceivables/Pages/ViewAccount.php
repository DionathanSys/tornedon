<?php

namespace App\Filament\Bpo\Resources\AccountReceivables\Pages;

use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Filament\Bpo\Resources\Pages\ViewFinancialTitle;

class ViewAccount extends ViewFinancialTitle
{
    protected static string $resource = AccountReceivableResource::class;
}
