<?php

namespace App\Listeners\Quote;

use App\Enum\Quote\Destination;
use App\Enum\Requisition\Status as RequisitionStatus;
use App\Events\Quote\QuoteApproved;
use App\Models\RequisitionItem;
use App\Services\Requisition\RequisitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateRequisitionFromApprovedQuoteListener
{
    /**
     * Handle the event.
     */
    public function handle(QuoteApproved $event): void
    {
        try {
            // Busca apenas os itens com destinação de requisição
            $quoteItems = $event->quote->items()
                ->where('destination', Destination::REQUISITION->value)
                ->get();

            if ($quoteItems->isEmpty()) {
                Log::info('CreateRequisitionFromApprovedQuoteListener: Nenhum item com destinação REQUISIÇÃO', [
                    'quote_id' => $event->quote->id,
                ]);
                return;
            }

            $discountAmount = $quoteItems->sum('discount_amount');

            DB::transaction(function () use ($event, $quoteItems, $discountAmount) {
                Log::debug('CreateRequisitionFromApprovedQuoteListener: Criando requisição', [
                    'quote_id' => $event->quote->id,
                    'items_count' => $quoteItems->count(),
                ]);

                $service = app(RequisitionService::class);
                $requisition = $service->create([
                    'company_id'    => $event->quote->company_id,
                    'customer_id'   => $event->quote->partner_id,
                    'sale_date'     => now()->toDateString(),
                    'status'        => RequisitionStatus::OPEN,
                    'discount_amount' => $discountAmount,
                    'payment_method' => $event->quote->payment_method,
                    'payment_condition' => $event->quote->payment_condition,
                    'observations'  => "Gerada a partir do orçamento #{$event->quote->quote_number}\n{$event->quote->observations}",
                ], $event->approvedBy);

                if (!$requisition) {
                    throw new \Exception('Erro ao criar requisição através do service: ' . $service->getMessage());
                }

                // Cria os itens da requisição
                foreach ($quoteItems as $quoteItem) {
                    RequisitionItem::create([
                        'requisition_id' => $requisition->id,
                        'product_id' => $quoteItem->product_id,
                        'unit_of_measure' => $quoteItem->unit_of_measure,
                        'quantity' => $quoteItem->quantity,
                        'unit_price' => $quoteItem->unit_price,
                        'discount_percentage' => $quoteItem->discount_percentage,
                        'discount_amount' => $quoteItem->discount_amount,
                        'observations' => $quoteItem->description,
                        'created_by' => $event->approvedBy,
                        'updated_by' => $event->approvedBy,
                    ]);
                }

                Log::info('CreateRequisitionFromApprovedQuoteListener: Requisição criada com sucesso', [
                    'quote_id' => $event->quote->id,
                    'requisition_id' => $requisition->id,
                    'items_count' => $quoteItems->count(),
                ]);
            });

        } catch (\Exception $e) {
            Log::error('CreateRequisitionFromApprovedQuoteListener: Erro ao criar requisição', [
                'quote_id' => $event->quote->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
