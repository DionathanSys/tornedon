<?php

namespace App\Listeners\RequisitionItem;

use App\Enum\StockMovement\Type;
use App\Events\RequisitionItem\RequisitionItemDeleted;
use App\Models\ProductStock;
use App\Models\StockMovement;
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

        $releaseBaseQuantity = (float) StockMovement::query()
            ->where('source_type', 'requisition_item')
            ->where('source_id', $item->id)
            ->whereIn('type', [
                Type::RESERVATION->value,
                Type::RESERVATION_RELEASE->value,
            ])
            ->get(['type', 'quantity', 'base_quantity'])
            ->sum(function (StockMovement $movement): float {
                $quantity = $movement->resolvedBaseQuantity();

                return $movement->type === Type::RESERVATION
                    ? $quantity
                    : -$quantity;
            });

        $releaseBaseQuantity = min($releaseBaseQuantity, $item->resolvedBaseQuantity());

        if ($releaseBaseQuantity <= 0.0001) {
            Log::info('HandleStockReservationDeleted: Nenhuma reserva pendente para liberar', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'product_id' => $product->id,
                'item_id' => $item->id,
            ]);

            return;
        }

        $conversionFactor = (float) ($item->conversion_factor_snapshot ?? 0);

        if ($conversionFactor <= 0) {
            $itemBaseQuantity = $item->resolvedBaseQuantity();
            $conversionFactor = $itemBaseQuantity > 0.0001
                ? $itemBaseQuantity / max((float) $item->quantity, 0.0001)
                : 1;
        }

        $releaseOperationalQuantity = round($releaseBaseQuantity / max($conversionFactor, 0.0001), 3);

        $movement = $this->stockMovementService->create([
            'product_stock_id' => $stock->id,
            'product_id'       => $product->id,
            'company_id'       => $stock->company_id,
            'type'             => Type::RESERVATION_RELEASE->value,
            'operational_unit' => $item->unit_of_measure ?? $product->unit?->value,
            'operational_quantity' => $releaseOperationalQuantity,
            'base_unit'        => $product->unit?->value,
            'base_quantity'    => $releaseBaseQuantity,
            'conversion_factor_snapshot' => $conversionFactor,
            'quantity'         => $releaseBaseQuantity,
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
