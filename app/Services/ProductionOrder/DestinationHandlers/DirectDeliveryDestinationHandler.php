<?php

namespace App\Services\ProductionOrder\DestinationHandlers;

use App\Enum\Requisition\Status;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use Illuminate\Support\Facades\Log;

class DirectDeliveryDestinationHandler
{
    public function handle(ProductionOrder $productionOrder, int $userId): bool
    {
        try {
            // Check if requisition already exists
            if ($productionOrder->requisition_id) {
                // Update existing requisition
                $requisition = Requisition::find($productionOrder->requisition_id);
                
                if ($requisition) {
                    // Update requisition items with produced quantities
                    foreach ($productionOrder->items as $item) {
                        $requisitionItem = $requisition->items()
                            ->where('product_id', $item->product_id)
                            ->first();

                        if ($requisitionItem) {
                            $requisitionItem->update([
                                'quantity' => $item->quantity_approved,
                            ]);
                        }
                    }
                }

                return true;
            }

            // Create new requisition for direct delivery
            $requisition = Requisition::create([
                'customer_id' => $productionOrder->partner_id,
                'company_id' => $productionOrder->company_id,
                'sale_date' => now(),
                'status' => Status::OPEN->value,
                'observations' => 'Gerado automaticamente pela ordem de produção #' . $productionOrder->production_order_number,
                'stock_consumed' => false, // Production order already handled the stock
                'created_by' => $userId,
            ]);

            // Create requisition items from production order
            foreach ($productionOrder->items as $item) {
                if ($item->quantity_approved > 0) {
                    RequisitionItem::create([
                        'requisition_id' => $requisition->id,
                        'product_id' => $item->product_id,
                        'description' => $item->description,
                        'quantity' => $item->quantity_approved,
                        'unit_of_measure' => $item->unit_of_measure,
                        'unit_price' => 0, // Will be filled manually or from quote
                        'discount_percentage' => 0,
                        'discount_amount' => 0,
                        'total_amount' => 0,
                    ]);
                }
            }

            // Link requisition to production order
            $productionOrder->update([
                'requisition_id' => $requisition->id,
            ]);

            Log::info('Requisition created from production order', [
                'production_order_id' => $productionOrder->id,
                'requisition_id' => $requisition->id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error creating requisition from production: ' . $e->getMessage(), [
                'production_order_id' => $productionOrder->id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        }
    }
}
