<?php

namespace App\Observers;

use App\Models\FiscalDocument;
use App\Services\Email\DocumentNotificationService;

class FiscalDocumentObserver
{
    public function updated(FiscalDocument $fiscalDocument): void
    {
        if (! $fiscalDocument->wasChanged('status')) {
            return;
        }

        app(DocumentNotificationService::class)->scheduleForFiscalDocumentStatusChange(
            $fiscalDocument,
            (string) $fiscalDocument->status->value,
        );
    }
}
