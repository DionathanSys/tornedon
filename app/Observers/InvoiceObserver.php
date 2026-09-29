<?php

namespace App\Observers;

use App\Enum\Invoice\Status as InvoiceStatus;
use App\Jobs\ScheduleBankSlipCancellationJob;
use App\Models\CompanyPreference;
use App\Models\Invoice;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;

class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        if (
            ($invoice->wasChanged('status') && $invoice->status === InvoiceStatus::CANCELLED)
            || ($invoice->wasChanged('canceled') && $invoice->canceled === true)
        ) {
            if (CompanyPreference::shouldCancelBankSlipsWhenInvoiceCancelled($invoice->company_id)) {
                ScheduleBankSlipCancellationJob::dispatch($invoice->id)->afterCommit();
            }
        }

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
