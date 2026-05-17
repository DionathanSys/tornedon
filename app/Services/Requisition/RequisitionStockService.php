<?php

namespace App\Services\Requisition;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RequisitionStockService
{
    public function __construct(
        private readonly ProductStockService $productStockService,
        private readonly StockMovementService $stockMovementService,
    ) {}

    public function pendingItems(Requisition $requisition, bool $withProduct = false): Collection
    {
        $query = $requisition->items()->whereNull('stock_consumed_at');

        if ($withProduct) {
            $query->with('product');
        }

        return $query->get();
    }

    public function resolveReservedQuantity(Requisition $requisition, RequisitionItem $item): float
    {
        $itemReservedQuantity = (float) StockMovement::query()
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

        $requestedBaseQuantity = $item->resolvedBaseQuantity();

        if ($itemReservedQuantity > 0.0001) {
            return min($itemReservedQuantity, $requestedBaseQuantity);
        }

        $reservedQuantity = (float) StockMovement::query()
            ->where('source_type', 'requisition')
            ->where('source_id', $requisition->id)
            ->where('product_id', $item->product_id)
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

        return min(max($reservedQuantity, 0), $requestedBaseQuantity);
    }

    public function markItemAsConsumed(RequisitionItem $item): void
    {
        Log::debug('RequisitionStockService: marcando item como consumido', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $item->requisition_id,
            'item_id' => $item->id,
        ]);

        $item->update([
            'stock_consumed' => true,
            'stock_consumed_at' => now(),
        ]);
    }

    public function syncConsumptionFlags(Requisition $requisition): void
    {
        $hasPendingItems = $requisition->items()
            ->whereNull('stock_consumed_at')
            ->exists();

        $requisition->update([
            'stock_consumed' => ! $hasPendingItems,
            'stock_reserved' => $hasPendingItems ? $requisition->stock_reserved : false,
        ]);
    }

    public function createReservation(
        Requisition $requisition,
        RequisitionItem $item,
        int $userId,
        string $reason,
        string $sourceType = 'requisition',
        ?int $sourceId = null,
    ): StockMovement {
        $productStock = $this->resolveProductStock($requisition, $item);
        $product = $item->product;

        if ($product === null) {
            throw new RuntimeException('Produto não encontrado para recriar reserva de estoque.');
        }

        $movement = $this->stockMovementService->create(array_merge(
            $this->movementBaseData($requisition, $item, $productStock, $sourceType, $sourceId),
            [
                'type' => Type::RESERVATION->value,
                'base_unit' => $product->unit?->value,
                'base_quantity' => $item->resolvedBaseQuantity(),
                'quantity' => $item->resolvedBaseQuantity(),
                'reason' => $reason,
            ]
        ), $userId);

        if (! $movement) {
            throw new RuntimeException(
                'Falha ao recriar reserva de estoque para produto #' . $item->product_id
                . ': ' . $this->stockMovementService->getMessage()
            );
        }

        return $movement;
    }

    public function createReservationRelease(
        Requisition $requisition,
        RequisitionItem $item,
        int $userId,
        string $reason,
        ?float $baseQuantity = null,
        string $sourceType = 'requisition',
        ?int $sourceId = null,
    ): ?StockMovement {
        $productStock = $this->resolveProductStock($requisition, $item);
        $product = $item->product;

        if ($product === null) {
            throw new RuntimeException('Produto não encontrado para liberar reserva de estoque.');
        }

        $resolvedBaseQuantity = $baseQuantity ?? $item->resolvedBaseQuantity();

        $movement = $this->stockMovementService->create(array_merge(
            $this->movementBaseData($requisition, $item, $productStock, $sourceType, $sourceId),
            [
                'type' => Type::RESERVATION_RELEASE->value,
                'base_unit' => $product->unit?->value,
                'base_quantity' => $resolvedBaseQuantity,
                'quantity' => $resolvedBaseQuantity,
                'reason' => $reason,
            ]
        ), $userId);

        if (! $movement) {
            throw new RuntimeException(
                'Falha ao liberar reserva de estoque para produto #' . $item->product_id
                . ': ' . $this->stockMovementService->getMessage()
            );
        }

        return $movement;
    }

    public function createExit(
        Requisition $requisition,
        RequisitionItem $item,
        int $userId,
        string $reason,
        string $sourceType = 'requisition',
        ?int $sourceId = null,
    ): StockMovement {
        $productStock = $this->resolveProductStock($requisition, $item);

        $movement = $this->stockMovementService->create(array_merge(
            $this->movementBaseData($requisition, $item, $productStock, $sourceType, $sourceId),
            [
                'type' => Type::EXIT->value,
                'reason' => $reason,
            ]
        ), $userId);

        if (! $movement) {
            throw new RuntimeException(
                'Falha ao criar saída de estoque para produto #' . $item->product_id
                . ': ' . $this->stockMovementService->getMessage()
            );
        }

        return $movement;
    }

    public function findProductStock(Requisition $requisition, RequisitionItem $item): ?ProductStock
    {
        if (! $item->product_id) {
            return null;
        }

        return $this->productStockService->findByProductId($item->product_id, $requisition->company_id);
    }

    private function resolveProductStock(Requisition $requisition, RequisitionItem $item): ProductStock
    {
        $productStock = $this->findProductStock($requisition, $item);

        if (! $productStock) {
            throw new RuntimeException('Estoque não encontrado para o produto #' . $item->product_id);
        }

        return $productStock;
    }

    private function movementBaseData(
        Requisition $requisition,
        RequisitionItem $item,
        ProductStock $productStock,
        string $sourceType,
        ?int $sourceId,
    ): array {
        $product = $item->product;

        return [
            'product_stock_id' => $productStock->id,
            'product_id' => $item->product_id,
            'company_id' => $requisition->company_id,
            'operational_unit' => $item->unit_of_measure ?? $product?->unit?->value,
            'operational_quantity' => (float) $item->quantity,
            'base_unit' => $product?->unit?->value,
            'base_quantity' => $item->resolvedBaseQuantity(),
            'conversion_factor_snapshot' => (float) ($item->conversion_factor_snapshot ?? 1),
            'quantity' => $item->resolvedBaseQuantity(),
            'unit_price' => (float) ($item->unit_price ?? 0),
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? $requisition->id,
            'observations' => $item->observations,
        ];
    }
}
