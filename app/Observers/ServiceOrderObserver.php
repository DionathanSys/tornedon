<?php

namespace App\Observers;

use App\Models\ServiceOrder;
use App\Services\Email\DocumentNotificationService;

class ServiceOrderObserver
{
    public function updated(ServiceOrder $serviceOrder): void
    {
        if (! $serviceOrder->wasChanged('status')) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForServiceOrderStatusChange(
            $serviceOrder,
            (string) $serviceOrder->status->value,
        );
    }
}
