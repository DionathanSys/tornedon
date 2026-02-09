<?php

namespace App\Services\Quote\Actions;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\Quote;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConvertToProductionOrder
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(Quote $quote, array $data = []): ?ProductionOrder
    {
        try {
            if (!$quote->canBeConverted()) {
                $this->setError('Este orçamento não pode ser convertido em ordem de produção');
                return null;
            }

            DB::beginTransaction();

            $productionOrderData = [
                'company_id' => $quote->company_id,
                'quote_id' => $quote->id,
                'partner_id' => $quote->partner_id,
                'status' => Status::QUEUED->value,
                'priority' => $data['priority'] ?? Priority::NORMAL->value,
                'destination_type' => $data['destination_type'] ?? DestinationType::STOCK->value,
                'observations' => $data['observations'] ?? $quote->observations,
                'created_by' => $this->createdBy,
            ];

            $productionOrder = ProductionOrder::create($productionOrderData);

            // Create production order items from quote items
            $totalEstimatedHours = 0;
            foreach ($quote->items as $index => $quoteItem) {
                ProductionOrderItem::create([
                    'production_order_id' => $productionOrder->id,
                    'quote_item_id' => $quoteItem->id,
                    'product_id' => $quoteItem->product_id,
                    'description' => $quoteItem->description,
                    'quantity' => $quoteItem->quantity,
                    'unit_of_measure' => $quoteItem->unit_of_measure,
                    'technical_specifications' => $quoteItem->technical_specifications,
                    'sequence' => $quoteItem->sequence,
                ]);

                if ($quoteItem->estimated_production_hours) {
                    $totalEstimatedHours += $quoteItem->estimated_production_hours;
                }
            }

            // Update production order with total estimated hours
            if ($totalEstimatedHours > 0) {
                $productionOrder->update(['total_estimated_hours' => $totalEstimatedHours]);
            }

            DB::commit();
            $this->setSuccess();
            
            return $productionOrder;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->setError('Erro ao converter orçamento: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'quote_id'   => $quote->id,
            ]);
            
            return null;
        }
    }
}
