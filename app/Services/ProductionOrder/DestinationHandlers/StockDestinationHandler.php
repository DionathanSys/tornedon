<?php

namespace App\Services\ProductionOrder\DestinationHandlers;

use App\Models\ProductionOrder;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Log;

class StockDestinationHandler
{
    public function handle(ProductionOrder $productionOrder, int $userId): bool
    {
        try {
            foreach ($productionOrder->items as $item) {
                if (!$item->product_id) {
                    // Skip items without product reference
                    Log::warning('Production order item without product_id, skipping stock entry', [
                        'production_order_item_id' => $item->id,
                        'description' => $item->description,
                    ]);
                    continue;
                }

                // Get or create product stock
                $productStock = ProductStock::firstOrCreate(
                    [
                        'product_id' => $item->product_id,
                    ],
                    [
                        'quantity_available' => 0,
                        'quantity_reserved' => 0,
                        'quantity_minimum' => 0,
                        'average_cost' => 0,
                        'is_active' => true,
                        'allow_negative' => false,
                        'company_id' => $productionOrder->company_id,
                        'created_by' => $userId,
                    ]
                );

                // Add produced quantity to stock
                $quantityToAdd = $item->quantity_approved;
                
                if ($quantityToAdd > 0) {
                    $newQuantity = $productStock->quantity_available + $quantityToAdd;
                    
                    $productStock->update([
                        'quantity_available' => $newQuantity,
                        'last_movement_date' => now(),
                        'last_movement_type' => 'PRODUCTION_ENTRY',
                        'updated_by' => $userId,
                    ]);

                    Log::info('Stock updated from production', [
                        'product_id' => $item->product_id,
                        'quantity_added' => $quantityToAdd,
                        'new_quantity' => $newQuantity,
                        'production_order_id' => $productionOrder->id,
                    ]);
                }
            }

            return true;
        } catch (\Exception $e) {
            Log::error('Error updating stock from production: ' . $e->getMessage(), [
                'production_order_id' => $productionOrder->id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return false;
        }
    }
}
