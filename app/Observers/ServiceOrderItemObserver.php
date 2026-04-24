<?php

namespace App\Observers;

use App\Models\ServiceOrderItem;
use App\Services\Amounts\CommercialAmountSyncService;

class ServiceOrderItemObserver
{
    public function saved(ServiceOrderItem $serviceOrderItem): void
    {
        app(CommercialAmountSyncService::class)->syncServiceOrder((int) $serviceOrderItem->service_order_id);
    }

    public function deleted(ServiceOrderItem $serviceOrderItem): void
    {
        app(CommercialAmountSyncService::class)->syncServiceOrder((int) $serviceOrderItem->service_order_id);
    }
}
