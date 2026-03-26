<?php

namespace App\Observers;

use App\Models\ServiceOrder;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;

class ServiceOrderObserver
{
    public function updated(ServiceOrder $serviceOrder): void
    {
        if (! $serviceOrder->wasChanged('status')) {
            return;
        }

        $shouldSend = DocumentNotificationDecisionContext::pull('service_order', (int) $serviceOrder->id);
        if ($shouldSend === false) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForServiceOrderStatusChange(
            $serviceOrder,
            (string) $serviceOrder->status->value,
        );
    }
}
