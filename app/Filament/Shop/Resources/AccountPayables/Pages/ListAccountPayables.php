<?php

namespace App\Filament\Shop\Resources\AccountPayables\Pages;

use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;

class ListAccountPayables extends \App\Filament\Clusters\Financial\Resources\AccountPayables\Pages\ListAccountPayables
{
    protected static string $resource = AccountPayableResource::class;
}
