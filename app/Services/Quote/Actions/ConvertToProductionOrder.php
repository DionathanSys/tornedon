<?php

namespace App\Services\Quote\Actions;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use App\Enum\Quote\Status as QuoteStatus;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class ConvertToProductionOrder
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Converte um orçamento aprovado em Ordem de Produção.
     *
     * @param  Quote  $quote
     * @param  array  $data
     * @return ProductionOrder|null
     */
    public function execute(Quote $quote, array $data = []): ?ProductionOrder
    {
        try {
            Log::debug('ConvertToProductionOrder: Iniciando conversão de orçamento', [
                'metodo'   => __METHOD__ . '@' . __LINE__,
                'quote_id' => $quote->id,
                'user_id'  => $this->createdBy,
            ]);

            if ($quote->status !== QuoteStatus::APPROVED) {
                $this->setError('Apenas orçamentos aprovados podem ser convertidos em Ordem de Produção.', [
                    'status' => ['Status atual: ' . $quote->status->description()],
                ]);
                return null;
            }

            if ($quote->productionOrder()->exists()) {
                $this->setError('Este orçamento já possui uma Ordem de Produção vinculada.');
                return null;
            }

            if ($quote->items()->count() === 0) {
                $this->setError('O orçamento não possui itens para converter em Ordem de Produção.');
                return null;
            }

            $productionOrderData = [
                'company_id'       => $quote->company_id,
                'quote_id'         => $quote->id,
                'customer_id'       => $quote->customer_id,
                'status'           => Status::QUEUED->value,
                'priority'         => $data['priority'] ?? Priority::NORMAL->value,
                'destination_type' => $data['destination_type'] ?? DestinationType::STOCK->value,
                'observations'     => $data['observations'] ?? $quote->observations,
                'created_by'       => $this->createdBy,
            ];

            $productionOrder = ProductionOrder::create($productionOrderData);

            $totalEstimatedHours = 0.0;
            foreach ($quote->items as $index => $quoteItem) {
                ProductionOrderItem::create([
                    'production_order_id'    => $productionOrder->id,
                    'quote_item_id'          => $quoteItem->id,
                    'product_id'             => $quoteItem->product_id,
                    'description'            => $quoteItem->resolveDescription(),
                    'quantity'               => $quoteItem->quantity,
                    'unit_of_measure'        => $quoteItem->unit_of_measure,
                    'technical_specifications' => $quoteItem->technical_specifications,
                    'sequence'               => $quoteItem->sequence ?? ($index + 1),
                ]);

                if ($quoteItem->estimated_production_hours) {
                    $totalEstimatedHours += (float) $quoteItem->estimated_production_hours;
                }
            }

            if ($totalEstimatedHours > 0) {
                $productionOrder->update(['total_estimated_hours' => $totalEstimatedHours]);
            }

            Log::info('ConvertToProductionOrder: Ordem de Produção criada com sucesso', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'quote_id'            => $quote->id,
                'production_order_id' => $productionOrder->id,
            ]);

            $this->setSuccess();
            return $productionOrder;

        } catch (QueryException $e) {
            $this->setError('Erro ao converter orçamento em Ordem de Produção no banco de dados');

            Log::error('ConvertToProductionOrder: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao converter orçamento: ' . $e->getMessage());

            Log::error('ConvertToProductionOrder: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'quote_id'   => $quote->id,
            ]);

            return null;
        }
    }
}

