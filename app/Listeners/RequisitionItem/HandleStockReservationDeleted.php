<?php

namespace App\Listeners\RequisitionItem;

use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Models\ProductStock;
use App\Services\ProductStock\ProductStockService;
use Illuminate\Support\Facades\Log;

class HandleStockReservationDeleted
{
    public function __construct(
        private ProductStockService $stockService,
    ) {}

    public function handle(RequisitionItemDeleted $event): void
    {
        $item    = $event->item;
        $product = $item->product;

        if (! $product || ! $product->has_stock_control) {
            return;
        }

        $stock = ProductStock::where('product_id', $product->id)
            ->where('company_id', $product->company_id)
            ->first();

        if (! $stock) {
            Log::warning('HandleStockReservationDeleted: Estoque não encontrado para o produto', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
                'item_id'    => $item->id,
            ]);
            return;
        }

        // Libera a quantidade reservada (delta negativo)
        $this->stockService->updateReservation(
            stock:         $stock,
            quantityDelta: -(float) $item->quantity,
            lastSalePrice: (float) $item->unit_price,
            movementType:  'requisition_item_deleted',
            updatedBy:     $event->deletedBy,
        );

        if ($this->stockService->hasError()) {
            Log::error('HandleStockReservationDeleted: Erro ao liberar reserva de estoque', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
                'item_id'    => $item->id,
                'error'      => $this->stockService->getMessage(),
            ]);
        }
    }
}
