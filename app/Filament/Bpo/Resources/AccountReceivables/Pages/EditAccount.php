<?php

namespace App\Filament\Bpo\Resources\AccountReceivables\Pages;

use App\Filament\Bpo\Resources\AccountReceivableResource;
use App\Filament\Bpo\Resources\Pages\EditFinancialTitle;

class EditAccount extends EditFinancialTitle
{
    protected static string $resource = AccountReceivableResource::class;
}
