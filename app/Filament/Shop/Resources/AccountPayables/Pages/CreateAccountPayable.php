<?php

namespace App\Filament\Shop\Resources\AccountPayables\Pages;

use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;

class CreateAccountPayable extends \App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\CreateAccountPayable
{
    protected static string $resource = AccountPayableResource::class;
}
