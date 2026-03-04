<?php

namespace App\Listeners\RequisitionItem;

use App\Enum\StockMovement\Type;
use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Models\ProductStock;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;
use App\Notification\NotifyService as notify;

class HandleStockReservationCreated
{
    public function __construct(
        private StockMovementService $stockMovementService,
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

        $movement = $this->stockMovementService->create([
            'product_stock_id' => $stock->id,
            'product_id'       => $product->id,
            'company_id'       => $stock->company_id,
            'type'             => Type::RESERVATION->value,
            'quantity'         => (float) $item->quantity,
            'unit_price'       => (float) ($item->unit_price ?? 0),
            'reason'           => 'Reserva por item de requisição',
            'source_type'      => 'requisition_item',
            'source_id'        => $item->id,
        ], $event->createdBy);

        if (! $movement) {
            Log::error('HandleStockReservationCreated: Erro ao reservar estoque', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
                'item_id'    => $item->id,
                'error'      => $this->stockMovementService->getMessage(),
            ]);
        }
    }
}
