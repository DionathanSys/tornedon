<?php

namespace App\Listeners\Quote;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\Quote\Destination;
use App\Events\Quote\QuoteApproved;
use App\Services\ProductionOrder\ProductionOrderService;
use App\Services\ProductionOrderItem\ProductionOrderItemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;

class CreateProductionOrderFromApprovedQuoteListener
{
    /**
     * Handle the event.
     */
    public function handle(QuoteApproved $event): void
    {
        $quote = $event->quote;

        // Busca apenas os itens com destinação de ordem de produção
        $quoteItems = $quote->items()
            ->where('destination', Destination::ORDER_PRODUCTION->value)
            ->get();

        if ($quoteItems->isEmpty()) {
            Log::info('CreateProductionOrderFromApprovedQuoteListener: Nenhum item com destinação PRODUÇÃO', [
                'quote_id' => $quote->id,
            ]);
            return;
        }

        try {
            DB::transaction(function () use ($event, $quote, $quoteItems) {
                Log::debug('CreateProductionOrderFromApprovedQuoteListener: Criando ordem de produção', [
                    'quote_id'    => $quote->id,
                    'items_count' => $quoteItems->count(),
                ]);

                // 1. Cria o cabeçalho da ordem de produção
                $productionOrderService = app(ProductionOrderService::class);
                $productionOrder = $productionOrderService->create([
                    'company_id'       => $quote->company_id,
                    'partner_id'       => $quote->partner_id,
                    'quote_id'         => $quote->id,
                    'priority'         => Priority::NORMAL->value,
                    'destination_type' => DestinationType::DIRECT_DELIVERY->value,
                    'observations'     => implode("\n", array_filter([
                        "Gerada a partir do orçamento #{$quote->quote_number}.",
                        $quote->customer_observations ? "Obs. Cliente: {$quote->customer_observations}." : null,
                        $quote->observations ? "Obs. Interna: {$quote->observations}." : null,
                    ])),
                ], $event->approvedBy);

                if (! $productionOrder) {
                    notify::error(
                        'Erro ao criar ordem de produção', 
                        $productionOrderService->getMessage(), 
                        true,
                        $event->approvedBy,
                        $productionOrderService->getErrorCode()
                    );
                    
                    throw new \Exception(
                        'Erro ao criar ordem de produção: ' . $productionOrderService->getMessage()
                    );
                }

                // 2. Cria os itens via ProductionOrderItemService (inclui quote_item_id diretamente)
                $itemService = app(ProductionOrderItemService::class);

                foreach ($quoteItems as $sequence => $quoteItem) {
                    $item = $itemService->create([
                        'production_order_id'      => $productionOrder->id,
                        'quote_item_id'            => $quoteItem->id,
                        'product_id'               => $quoteItem->product_id,
                        'description'              => $quoteItem->description,
                        'quantity'                 => $quoteItem->quantity,
                        'unit_of_measure'          => $quoteItem->unit_of_measure,
                        'technical_specifications' => $quoteItem->technical_specifications,
                        'sequence'                 => $sequence + 1,
                    ], $event->approvedBy);

                    if (! $item) {
                        notify::error(
                            'Erro ao criar item da ordem de produção', 
                            $itemService->getMessage(), 
                            true,
                            $event->approvedBy,
                            $itemService->getErrorCode()
                        );
                        throw new \Exception(
                            "Erro ao criar item da ordem de produção (quote_item_id={$quoteItem->id}): "
                            . $itemService->getMessage()
                        );
                    }
                }

                // Marca os itens do orçamento como vinculados (sem erros)
                foreach ($quoteItems as $quoteItem) {
                    $quoteItem->update(['status' => \App\Enum\Quote\Status::LINKED]);
                }

                Log::info('CreateProductionOrderFromApprovedQuoteListener: Ordem de produção criada com sucesso', [
                    'quote_id'            => $quote->id,
                    'production_order_id' => $productionOrder->id,
                    'items_count'         => $quoteItems->count(),
                ]);
            });

        } catch (\Exception $e) {
            notify::error(
                'Erro ao criar ordem de produção', 
                'Contate o administrador do sistema.',
                true,
                $event->approvedBy
            );
            Log::error('CreateProductionOrderFromApprovedQuoteListener: Erro ao criar ordem de produção', [
                'quote_id' => $quote->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
