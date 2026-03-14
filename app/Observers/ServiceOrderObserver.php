<?php

namespace App\Observers;

use App\Models\ServiceOrder;
use App\Services\Email\CustomerDocumentEmailService;

class ServiceOrderObserver
{
    public function updated(ServiceOrder $serviceOrder): void
    {
        if (! $serviceOrder->wasChanged('status')) {
            return;
        }

        $serviceOrder->loadMissing('customer');

        app(CustomerDocumentEmailService::class)->sendServiceOrderStatusUpdated(
            $serviceOrder,
            (string) $serviceOrder->getOriginal('status'),
            (string) $serviceOrder->status->value,
        );
    }
}
