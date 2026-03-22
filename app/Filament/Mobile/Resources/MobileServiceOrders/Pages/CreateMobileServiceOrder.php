<?php

namespace App\Filament\Mobile\Resources\MobileServiceOrders\Pages;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\CreateServiceOrder;
use App\Filament\Mobile\Resources\MobileServiceOrders\MobileServiceOrderResource;

class CreateMobileServiceOrder extends CreateServiceOrder
{
    protected static string $resource = MobileServiceOrderResource::class;
}
