<?php

namespace App\Listeners\RequisitionItem;

use App\Enum\StockMovement\Type;
use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Models\ProductStock;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;

class HandleStockReservationDeleted
{
    public function __construct(
        private StockMovementService $stockMovementService,
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

        $movement = $this->stockMovementService->create([
            'product_stock_id' => $stock->id,
            'product_id'       => $product->id,
            'company_id'       => $stock->company_id,
            'type'             => Type::RESERVATION_RELEASE->value,
            'operational_unit' => $item->unit_of_measure ?? $product->unit?->value,
            'quantity'         => (float) $item->quantity,
            'unit_price'       => (float) ($item->unit_price ?? 0),
            'reason'           => 'Liberação de reserva por exclusão de item de requisição',
            'source_type'      => 'requisition_item',
            'source_id'        => $item->id,
        ], $event->deletedBy);

        if (! $movement) {
            Log::error('HandleStockReservationDeleted: Erro ao liberar reserva de estoque', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
                'item_id'    => $item->id,
                'error'      => $this->stockMovementService->getMessage(),
            ]);
        }
    }
}
