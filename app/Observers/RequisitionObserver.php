<?php

namespace App\Observers;

use App\Models\Requisition;
use App\Services\Email\DocumentNotificationService;

class RequisitionObserver
{
    public function updated(Requisition $requisition): void
    {
        if (! $requisition->wasChanged('status')) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForRequisitionStatusChange(
            $requisition,
            (string) $requisition->status->value,
        );
    }
}
