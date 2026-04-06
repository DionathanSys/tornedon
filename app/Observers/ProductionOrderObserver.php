<?php

namespace App\Observers;

use App\Models\ProductionOrder;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;
use BackedEnum;

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
            $this->normalizeStatus($productionOrder->getOriginal('status')),
            $this->normalizeStatus($productionOrder->status),
        );
    }

    private function normalizeStatus(mixed $status): string
    {
        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        return (string) $status;
    }
}
