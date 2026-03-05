<?php

namespace App\Listeners\Quote;

use App\Enum\Quote\Status;
use App\Events\Quote\QuoteReopened;
use Illuminate\Support\Facades\Log;

class UpdateQuoteItemsStatusOnReopenListener
{
    /**
     * Handle the event.
     */
    public function handle(QuoteReopened $event): void
    {
        try {
            Log::debug('UpdateQuoteItemsStatusOnReopenListener: Redefinindo status dos itens para rascunho', [
                'quote_id' => $event->quote->id,
            ]);

            // A reabertura é controlada pelo State do Quote, que já garante que
            // não existem documentos filhos antes de permitir a transição.
            // Portanto, todos os itens devem voltar para DRAFT.
            $count = $event->quote->items()
                ->update([
                    'status' => Status::DRAFT,
                ]);

            Log::info('UpdateQuoteItemsStatusOnReopenListener: Status dos itens redefinido com sucesso', [
                'quote_id'    => $event->quote->id,
                'items_count' => $count,
            ]);

        } catch (\Exception $e) {
            Log::error('UpdateQuoteItemsStatusOnReopenListener: Erro ao redefinir status dos itens', [
                'quote_id' => $event->quote->id,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
