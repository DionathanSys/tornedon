<?php

namespace App\Observers;

use App\Models\ProductionOrder;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;

class ProductionOrderObserver
{
    public function updated(ProductionOrder $productionOrder): void
    {
        if (! $productionOrder->wasChanged('status')) {
            return;
        }

        $shouldSend = DocumentNotificationDecisionContext::pull('production_order', (int) $productionOrder->id);
        if ($shouldSend === false) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForProductionOrderStatusChange(
            $productionOrder,
            (string) $productionOrder->status->value,
        );
    }
}
