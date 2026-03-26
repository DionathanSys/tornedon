<?php

namespace App\Observers;

use App\Models\FiscalDocument;
use App\Services\Email\DocumentNotificationService;
use App\Support\Email\DocumentNotificationDecisionContext;

class FiscalDocumentObserver
{
    public function updated(FiscalDocument $fiscalDocument): void
    {
        if (! $fiscalDocument->wasChanged('status')) {
            return;
        }

        $shouldSend = DocumentNotificationDecisionContext::pull('fiscal_document', (int) $fiscalDocument->id);
        if ($shouldSend === false) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForFiscalDocumentStatusChange(
            $fiscalDocument,
            (string) $fiscalDocument->status->value,
        );
    }
}
