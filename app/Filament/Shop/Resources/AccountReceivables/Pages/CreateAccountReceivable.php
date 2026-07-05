<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;

class CreateAccountReceivable extends \App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\CreateAccountReceivable
{
    protected static string $resource = AccountReceivableResource::class;
}
