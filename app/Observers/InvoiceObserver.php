<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;

class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        $shouldSend = DocumentNotificationDecisionContext::pull('invoice', (int) $invoice->id);
        if ($shouldSend === false) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForInvoiceStatusChange(
            $invoice,
            (string) $invoice->status->value,
        );
    }
}
