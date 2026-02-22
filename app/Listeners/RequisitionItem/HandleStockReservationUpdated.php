<?php

namespace App\Listeners\RequisitionItem;

use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Models\ProductStock;
use App\Services\ProductStock\ProductStockService;
use Illuminate\Support\Facades\Log;

class HandleStockReservationUpdated
{
    public function __construct(
        private ProductStockService $stockService,
    ) {}

    public function handle(RequisitionItemUpdated $event): void
    {
        $item       = $event->item;
        $newProduct = $item->product;

        $productChanged = $event->oldProductId !== $item->product_id;
        $quantityDelta  = (float) $item->quantity - $event->oldQuantity;

        // Se o produto mudou → libera reserva do produto antigo e reserva no novo
        if ($productChanged) {
            $this->releaseOldProductReservation(
                oldProductId:  $event->oldProductId,
                quantity:      $event->oldQuantity,
                updatedBy:     $event->updatedBy,
            );

            if ($newProduct && $newProduct->has_stock_control) {
                $newStock = ProductStock::where('product_id', $newProduct->id)
                    ->where('company_id', $newProduct->company_id)
                    ->first();

                if ($newStock) {
                    $this->stockService->updateReservation(
                        stock:         $newStock,
                        quantityDelta: (float) $item->quantity,
                        lastSalePrice: (float) $item->unit_price,
                        movementType:  'requisition_item_updated',
                        updatedBy:     $event->updatedBy,
                    );
                }
            }

            return;
        }

        // Mesmo produto → ajusta pelo delta de quantidade
        if ($quantityDelta == 0 && (float) $item->unit_price === $event->oldUnitPrice) {
            return;
        }

        if (! $newProduct || ! $newProduct->has_stock_control) {
            return;
        }

        $stock = ProductStock::where('product_id', $newProduct->id)
            ->where('company_id', $newProduct->company_id)
            ->first();

        if (! $stock) {
            Log::warning('HandleStockReservationUpdated: Estoque não encontrado para o produto', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $newProduct->id,
                'item_id'    => $item->id,
            ]);
            return;
        }

        $this->stockService->updateReservation(
            stock:         $stock,
            quantityDelta: $quantityDelta,
            lastSalePrice: (float) $item->unit_price,
            movementType:  'requisition_item_updated',
            updatedBy:     $event->updatedBy,
        );

        if ($this->stockService->hasError()) {
            Log::error('HandleStockReservationUpdated: Erro ao ajustar reserva de estoque', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $newProduct->id,
                'item_id'    => $item->id,
                'error'      => $this->stockService->getMessage(),
            ]);
        }
    }

    private function releaseOldProductReservation(int $oldProductId, float $quantity, int $updatedBy): void
    {
        $oldStock = ProductStock::where('product_id', $oldProductId)->first();

        if (! $oldStock) {
            return;
        }

        $this->stockService->updateReservation(
            stock:         $oldStock,
            quantityDelta: -$quantity,
            lastSalePrice: (float) $oldStock->last_sale_price,
            movementType:  'requisition_item_product_changed',
            updatedBy:     $updatedBy,
        );

        if ($this->stockService->hasError()) {
            Log::error('HandleStockReservationUpdated: Erro ao liberar estoque do produto antigo', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'old_product_id' => $oldProductId,
                'error'          => $this->stockService->getMessage(),
            ]);
        }
    }
}
