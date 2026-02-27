<?php

namespace App\Listeners\Quote;

use App\Enum\Quote\Destination;
use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Events\Quote\QuoteApproved;
use App\Services\ProductionOrder\ProductionOrderService;
use App\Services\ProductionOrderItem\ProductionOrderItemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateProductionOrderFromApprovedQuoteListener
{
    /**
     * Handle the event.
     */
    public function handle(QuoteApproved $event): void
    {
        try {
            // Busca apenas os itens com destinação de ordem de produção
            $quoteItems = $event->quote->items()
                ->where('destination', Destination::ORDER_PRODUCTION->value)
                ->get();

            if ($quoteItems->isEmpty()) {
                Log::info('CreateProductionOrderFromApprovedQuoteListener: Nenhum item com destinação PRODUÇÃO', [
                    'quote_id' => $event->quote->id,
                ]);
                return;
            }

            DB::transaction(function () use ($event, $quoteItems) {
                Log::debug('CreateProductionOrderFromApprovedQuoteListener: Criando ordem de produção', [
                    'quote_id' => $event->quote->id,
                    'items_count' => $quoteItems->count(),
                ]);

                // Prepara os itens da ordem de produção
                $items = $quoteItems->map(function ($quoteItem, $index) {
                    return [
                        'product_id' => $quoteItem->product_id,
                        'description' => $quoteItem->description,
                        'quantity' => $quoteItem->quantity,
                        'unit_of_measure' => $quoteItem->unit_of_measure,
                        'technical_specifications' => $quoteItem->technical_specifications,
                    ];
                })->toArray();

                // Cria a ordem de produção via service
                $productionOrderService = app(ProductionOrderService::class);
                $productionOrder = $productionOrderService->create([
                    'company_id' => $event->quote->company_id,
                    'partner_id' => $event->quote->partner_id,
                    'quote_id' => $event->quote->id,
                    'priority' => Priority::NORMAL->value,
                    'destination_type' => DestinationType::STOCK->value,
                    'observations' => "Gerada a partir do orçamento #{$event->quote->quote_number}\n{$event->quote->observations}",
                    'items' => $items,
                ], $event->approvedBy);

                if (!$productionOrder) {
                    throw new \Exception('Erro ao criar ordem de produção através do service: ' . $productionOrderService->getMessage());
                }

                // Cria os itens da ordem de produção via ProductionOrderItemService
                $itemService = app(ProductionOrderItemService::class);
                $sequence = 1;
                foreach ($quoteItems as $quoteItem) {
                    // Encontra o item criado da ordem de produção
                    $productionOrderItem = $productionOrder->items()
                        ->where('product_id', $quoteItem->product_id)
                        ->where('description', $quoteItem->description)
                        ->first();

                    if ($productionOrderItem) {
                        // Atualiza com quote_item_id
                        $item = $itemService->update($productionOrderItem, [
                            'quote_item_id' => $quoteItem->id,
                            'sequence' => $sequence++,
                        ], $event->approvedBy);

                        if (!$item) {
                            throw new \Exception('Erro ao atualizar item da ordem de produção: ' . $itemService->getMessage());
                        }
                    }
                }

                Log::info('CreateProductionOrderFromApprovedQuoteListener: Ordem de produção criada com sucesso', [
                    'quote_id' => $event->quote->id,
                    'production_order_id' => $productionOrder->id,
                    'items_count' => $quoteItems->count(),
                ]);
            });

        } catch (\Exception $e) {
            Log::error('CreateProductionOrderFromApprovedQuoteListener: Erro ao criar ordem de produção', [
                'quote_id' => $event->quote->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
