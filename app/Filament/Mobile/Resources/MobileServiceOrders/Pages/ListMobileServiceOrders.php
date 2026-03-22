<?php

namespace App\Filament\Mobile\Resources\MobileServiceOrders\Pages;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\ListServiceOrders;
use App\Filament\Mobile\Resources\MobileServiceOrders\MobileServiceOrderResource;

class ListMobileServiceOrders extends ListServiceOrders
{
    protected static string $resource = MobileServiceOrderResource::class;
}
