<?php

namespace App\Observers;

use App\Models\ServiceOrder;
use App\Services\Amounts\CommercialAmountSyncService;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;
use BackedEnum;

class ServiceOrderObserver
{
    public function created(ServiceOrder $serviceOrder): void
    {
        app(CommercialAmountSyncService::class)->syncServiceOrder($serviceOrder);
    }

    public function updated(ServiceOrder $serviceOrder): void
    {
        if ($serviceOrder->wasChanged('travel_value')) {
            app(CommercialAmountSyncService::class)->syncServiceOrder($serviceOrder);
        }

        if (! $serviceOrder->wasChanged('status')) {
            return;
        }

        $shouldSend = DocumentNotificationDecisionContext::pull('service_order', (int) $serviceOrder->id);
        if ($shouldSend === false) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForServiceOrderStatusChange(
            $serviceOrder,
            $this->normalizeStatus($serviceOrder->getOriginal('status')),
            $this->normalizeStatus($serviceOrder->status),
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
