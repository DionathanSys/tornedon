<?php

namespace App\Observers;

use App\Models\Invoice;

class InvoiceObserver
{
    public function updated(Invoice $invoice): void
    {
        // Notificação por e-mail de Invoice descontinuada no fluxo atual.
    }
}
