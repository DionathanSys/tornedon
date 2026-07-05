<?php

namespace App\Filament\Shop\Resources\AccountReceivables\Pages;

use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;

class ListAccountReceivables extends \App\Filament\Clusters\Financial\Resources\AccountReceivables\Pages\ListAccountReceivables
{
    protected static string $resource = AccountReceivableResource::class;
}
