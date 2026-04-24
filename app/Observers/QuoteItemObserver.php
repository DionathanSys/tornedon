<?php

namespace App\Observers;

use App\Models\QuoteItem;
use App\Services\Amounts\CommercialAmountSyncService;

class QuoteItemObserver
{
    public function saved(QuoteItem $quoteItem): void
    {
        app(CommercialAmountSyncService::class)->syncQuote((int) $quoteItem->quote_id);
    }

    public function deleted(QuoteItem $quoteItem): void
    {
        app(CommercialAmountSyncService::class)->syncQuote((int) $quoteItem->quote_id);
    }
}
