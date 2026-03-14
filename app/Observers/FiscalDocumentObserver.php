<?php

namespace App\Observers;

use App\Models\FiscalDocument;
use App\Services\Email\CustomerDocumentEmailService;

class FiscalDocumentObserver
{
    public function updated(FiscalDocument $fiscalDocument): void
    {
        if (! $fiscalDocument->wasChanged('status')) {
            return;
        }

        $fiscalDocument->loadMissing('customer');

        app(CustomerDocumentEmailService::class)->sendFiscalDocumentStatusUpdated(
            $fiscalDocument,
            (string) $fiscalDocument->getOriginal('status'),
            (string) $fiscalDocument->status->value,
        );
    }
}
