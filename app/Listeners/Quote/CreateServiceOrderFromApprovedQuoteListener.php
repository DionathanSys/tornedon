<?php

namespace App\Listeners\Quote;

use App\Enum\Quote\Destination;
use App\Enum\ServiceOrder\Priority;
use App\Enum\ServiceOrder\State as ServiceOrderState;
use App\Enum\ServiceOrder\Type;
use App\Events\Quote\QuoteApproved;
use App\Models\Quote;
use App\Services\Payment\CustomerPaymentDefaultsResolver;
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
            DB::transaction(function () use ($event) {
                $quote = Quote::query()
                    ->lockForUpdate()
                    ->findOrFail($event->quote->id);

                if ($quote->serviceOrders()->exists()) {
                    $this->linkRequisition($quote);

                    Log::warning('CreateServiceOrderFromApprovedQuoteListener: Ordem de servico ja existe para este orcamento, ignorando', [
                        'quote_id' => $quote->id,
                    ]);

                    return;
                }

                $quoteItems = $quote->items()
                    ->where('destination', Destination::ORDER_SERVICE->value)
                    ->get();

                if ($quoteItems->isEmpty()) {
                    Log::info('CreateServiceOrderFromApprovedQuoteListener: Nenhum item com destinacao SERVICO', [
                        'quote_id' => $quote->id,
                    ]);

                    return;
                }

                Log::debug('CreateServiceOrderFromApprovedQuoteListener: Criando ordem de servico', [
                    'quote_id' => $quote->id,
                    'items_count' => $quoteItems->count(),
                ]);

                $totalEstimatedHours = $quoteItems->sum('estimated_production_hours');
                $paymentDefaults = app(CustomerPaymentDefaultsResolver::class)->resolve(
                    (int) $quote->company_id,
                    (int) $quote->customer_id,
                    $quote->payment_method,
                    $quote->payment_condition,
                );

                $serviceOrderService = app(ServiceOrderService::class);
                $serviceOrder = $serviceOrderService->create([
                    'customer_id' => $quote->customer_id,
                    'company_id' => $quote->company_id,
                    'quote_id' => $quote->id,
                    'order_date' => now()->toDateString(),
                    'scheduled_date' => now()->addDays(7)->toDateString(),
                    'status' => ServiceOrderState::OPEN->value,
                    'priority' => Priority::NORMAL->value,
                    'type' => Type::MAINTENANCE->value,
                    'estimated_hours' => $totalEstimatedHours,
                    'payment_method' => $paymentDefaults['payment_method'],
                    'payment_condition' => $paymentDefaults['payment_condition'],
                    'customer_observations' => "Gerada a partir do orcamento #{$quote->quote_number}\n{$quote->observations}",
                ], $event->approvedBy);

                if (! $serviceOrder) {
                    throw new \Exception('Erro ao criar ordem de servico atraves do service: '.$serviceOrderService->getMessage());
                }

                $itemService = app(ServiceOrderItemService::class);
                foreach ($quoteItems as $quoteItem) {
                    $item = $itemService->create([
                        'service_order_id' => $serviceOrder->id,
                        'service_id' => $quoteItem->service_id,
                        'quantity' => $quoteItem->quantity,
                        'unit_price' => $quoteItem->unit_price,
                        'discount_percentage' => $quoteItem->discount_percentage,
                        'discount_amount' => $quoteItem->discount_amount,
                        'observations' => $quoteItem->description,
                    ], $event->approvedBy);

                    if (! $item) {
                        throw new \Exception('Erro ao criar item da ordem de servico: '.$itemService->getMessage());
                    }
                }

                foreach ($quoteItems as $quoteItem) {
                    $quoteItem->update(['status' => \App\Enum\Quote\Status::LINKED]);
                }

                $this->linkRequisition($quote, $serviceOrder->id);

                Log::info('CreateServiceOrderFromApprovedQuoteListener: Ordem de servico criada com sucesso', [
                    'quote_id' => $quote->id,
                    'service_order_id' => $serviceOrder->id,
                    'items_count' => $quoteItems->count(),
                    'total_estimated_hours' => $totalEstimatedHours,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('CreateServiceOrderFromApprovedQuoteListener: Erro ao criar ordem de servico', [
                'quote_id' => $event->quote->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function linkRequisition(Quote $quote, ?int $serviceOrderId = null): void
    {
        $serviceOrderId ??= $quote->serviceOrders()->value('id');

        if ($serviceOrderId === null) {
            return;
        }

        $updated = $quote->requisitions()
            ->where('company_id', $quote->company_id)
            ->whereNull('service_order_id')
            ->update(['service_order_id' => $serviceOrderId]);

        if ($updated > 0) {
            Log::info('CreateServiceOrderFromApprovedQuoteListener: Requisição vinculada à ordem de serviço', [
                'quote_id' => $quote->id,
                'service_order_id' => $serviceOrderId,
                'requisitions_linked' => $updated,
            ]);
        }
    }
}
