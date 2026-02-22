<?php

namespace App\Listeners\RequisitionItem;

use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Models\ProductStock;
use App\Services\ProductStock\ProductStockService;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;

class HandleStockReservationCreated
{
    public function __construct(
        private ProductStockService $stockService,
    ) {}

    public function handle(RequisitionItemCreated $event): void
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
            notify::warning(
                message: 'Estoque não encontrado para o produto '. $product->name . ', solicite ajuda ao suporte.',
                toDatabase: true,
                users: $event->createdBy,
            );
            
            Log::warning('HandleStockReservationCreated: Estoque não encontrado para o produto', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
                'item_id'    => $item->id,
            ]);
            return;
        }

        $this->stockService->updateReservation(
            stock:        $stock,
            quantityDelta: (float) $item->quantity,
            lastSalePrice: (float) $item->unit_price,
            movementType:  'requisition_item_created',
            updatedBy:     $event->createdBy,
        );

        if ($this->stockService->hasError()) {
            Log::error('HandleStockReservationCreated: Erro ao reservar estoque', [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'product_id'    => $product->id,
                'item_id'       => $item->id,
                'error'         => $this->stockService->getMessage(),
            ]);
        }
    }
}
