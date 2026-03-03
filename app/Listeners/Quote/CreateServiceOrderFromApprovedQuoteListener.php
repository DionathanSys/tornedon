<?php

namespace App\Listeners\Quote;

use App\Enum\Quote\Destination;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type;
use App\Events\Quote\QuoteApproved;
use App\Services\ServiceOrder\ServiceOrderService;
use App\Services\ServiceOrderItem\ServiceOrderItemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateServiceOrderFromApprovedQuoteListener
{
    /**
     * Handle the event.
     */
    public function handle(QuoteApproved $event): void
    {
        try {
            // Busca apenas os itens com destinação de ordem de serviço
            $quoteItems = $event->quote->items()
                ->where('destination', Destination::ORDER_SERVICE->value)
                ->get();

            if ($quoteItems->isEmpty()) {
                Log::info('CreateServiceOrderFromApprovedQuoteListener: Nenhum item com destinação SERVIÇO', [
                    'quote_id' => $event->quote->id,
                ]);
                return;
            }

            DB::transaction(function () use ($event, $quoteItems) {
                Log::debug('CreateServiceOrderFromApprovedQuoteListener: Criando ordem de serviço', [
                    'quote_id' => $event->quote->id,
                    'items_count' => $quoteItems->count(),
                ]);

                // Calcula total de horas estimadas
                $totalEstimatedHours = $quoteItems->sum('estimated_production_hours');

                // Cria a ordem de serviço via service
                $serviceOrderService = app(ServiceOrderService::class);
                $serviceOrder = $serviceOrderService->create([
                    'customer_id' => $event->quote->customer_id,
                    'company_id' => $event->quote->company_id,
                    'quote_id' => $event->quote->id,
                    'order_date' => now()->toDateString(),
                    'scheduled_date' => now()->addDays(7)->toDateString(), // Padrão: 7 dias
                    'status' => ServiceOrderState::OPEN->value,
                    'priority' => Priority::NORMAL->value,
                    'type' => Type::MAINTENANCE->value, // Tipo padrão
                    'estimated_hours' => $totalEstimatedHours,
                    'payment_method' => $event->quote->payment_method,
                    'payment_condition' => $event->quote->payment_condition,
                    'customer_observations' => "Gerada a partir do orçamento #{$event->quote->quote_number}\n{$event->quote->observations}",
                ], $event->approvedBy);

                if (!$serviceOrder) {
                    throw new \Exception('Erro ao criar ordem de serviço através do service: ' . $serviceOrderService->getMessage());
                }

                // Cria os itens da ordem de serviço via ServiceOrderItemService
                $itemService = app(ServiceOrderItemService::class);
                foreach ($quoteItems as $quoteItem) {
                    $item = $itemService->create([
                        'service_order_id' => $serviceOrder->id,
                        'service_id' => $quoteItem->service_id,
                        'unit_of_measure' => $quoteItem->unit_of_measure,
                        'quantity' => $quoteItem->quantity,
                        'unit_price' => $quoteItem->unit_price,
                        'discount_percentage' => $quoteItem->discount_percentage,
                        'discount_amount' => $quoteItem->discount_amount,
                        'observations' => $quoteItem->description,
                    ], $event->approvedBy);

                    if (!$item) {
                        throw new \Exception('Erro ao criar item da ordem de serviço: ' . $itemService->getMessage());
                    }
                }

                // Marca os itens do orçamento como vinculados (sem erros)
                foreach ($quoteItems as $quoteItem) {
                    $quoteItem->update(['status' => \App\Enum\Quote\Status::LINKED]);
                }

                Log::info('CreateServiceOrderFromApprovedQuoteListener: Ordem de serviço criada com sucesso', [
                    'quote_id' => $event->quote->id,
                    'service_order_id' => $serviceOrder->id,
                    'items_count' => $quoteItems->count(),
                    'total_estimated_hours' => $totalEstimatedHours,
                ]);
            });

        } catch (\Exception $e) {
            Log::error('CreateServiceOrderFromApprovedQuoteListener: Erro ao criar ordem de serviço', [
                'quote_id' => $event->quote->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
