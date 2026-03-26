<?php

namespace App\Observers;

use App\Models\Requisition;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;

class RequisitionObserver
{
    public function updated(Requisition $requisition): void
    {
        if (! $requisition->wasChanged('status')) {
            return;
        }

        $shouldSend = DocumentNotificationDecisionContext::pull('requisition', (int) $requisition->id);
        if ($shouldSend === false) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForRequisitionStatusChange(
            $requisition,
            (string) $requisition->status->value,
        );
    }
}
