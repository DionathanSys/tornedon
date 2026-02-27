<?php

namespace App\Listeners\Quote;

use App\Enum\Quote\Status;
use App\Events\Quote\QuoteApproved;
use Illuminate\Support\Facades\Log;

class UpdateQuoteItemsStatusListener
{
    /**
     * Handle the event.
     */
    public function handle(QuoteApproved $event): void
    {
        try {
            Log::debug('UpdateQuoteItemsStatusListener: Atualizando status dos itens', [
                'quote_id' => $event->quote->id,
            ]);

            // Atualiza o status de todos os itens para aprovado
            $event->quote->items()->update([
                'status' => Status::APPROVED,
            ]);

            Log::info('UpdateQuoteItemsStatusListener: Status dos itens atualizado com sucesso', [
                'quote_id' => $event->quote->id,
                'items_count' => $event->quote->items()->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('UpdateQuoteItemsStatusListener: Erro ao atualizar status dos itens', [
                'quote_id' => $event->quote->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
