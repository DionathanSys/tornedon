<?php

namespace App\Filament\Mobile\Resources\MobileServiceOrders\Pages;

use App\Filament\Clusters\Sales\Resources\ServiceOrders\Pages\EditServiceOrder;
use App\Filament\Mobile\Resources\MobileServiceOrders\MobileServiceOrderResource;

class EditMobileServiceOrder extends EditServiceOrder
{
    protected static string $resource = MobileServiceOrderResource::class;
}
