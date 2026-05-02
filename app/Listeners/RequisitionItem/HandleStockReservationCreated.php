<?php

namespace App\Listeners\RequisitionItem;

use App\Enum\StockMovement\Type;
use App\Events\RequisitionItem\RequisitionItemCreated;
use App\Models\ProductStock;
use App\Models\StockMovement;
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
        Log::info('HandleStockReservationCreated: Item criado', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'item_id'    => $event->item->id,
            'created_by' => $event->createdBy,
        ]);
        
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

        $existingReservation = StockMovement::query()
            ->where('source_type', 'requisition_item')
            ->where('source_id', $item->id)
            ->where('type', Type::RESERVATION->value)
            ->exists();

        if ($existingReservation) {
            Log::warning('HandleStockReservationCreated: Reserva ja existente para o item; duplicidade evitada', [
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
            'operational_unit' => $item->unit_of_measure ?? $product->unit?->value,
            'operational_quantity' => (float) $item->quantity,
            'base_unit'        => $product->unit?->value,
            'base_quantity'    => $item->resolvedBaseQuantity(),
            'conversion_factor_snapshot' => (float) ($item->conversion_factor_snapshot ?? 1),
            'quantity'         => $item->resolvedBaseQuantity(),
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
