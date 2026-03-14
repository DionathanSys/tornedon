<?php

namespace App\Observers;

use App\Models\Requisition;
use App\Services\Email\CustomerDocumentEmailService;

class RequisitionObserver
{
    public function updated(Requisition $requisition): void
    {
        if (! $requisition->wasChanged('status')) {
            return;
        }

        $requisition->loadMissing('customer');

        app(CustomerDocumentEmailService::class)->sendRequisitionStatusUpdated(
            $requisition,
            (string) $requisition->getOriginal('status'),
            (string) $requisition->status->value,
        );
    }
}
