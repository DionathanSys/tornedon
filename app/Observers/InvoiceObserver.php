<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\Email\CustomerDocumentEmailService;

class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        $invoice->loadMissing('customer');

        app(CustomerDocumentEmailService::class)->sendInvoiceStatusUpdated(
            $invoice,
            (string) $invoice->getOriginal('status'),
            (string) $invoice->status->value,
        );
    }
}
