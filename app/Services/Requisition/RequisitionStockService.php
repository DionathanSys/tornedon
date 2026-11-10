<?php

namespace App\Services\Requisition;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\Access\RoleRecordScope;
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
        if ($requisition->stock_control_enabled === false) {
            return new Collection;
        }
        $query = $requisition->items()->whereNull('stock_consumed_at')
            ->where(fn ($query) => $query->where('stock_control_snapshot', true)->orWhere(fn ($query) => $query->whereNull('stock_control_snapshot')->whereHas('product', fn ($query) => $query->where('has_stock_control', true))));

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
            'metodo' => __METHOD__.'@'.__LINE__,
            'requisition_id' => $item->requisition_id,
            'item_id' => $item->id,
        ]);

        $item->update([
            'stock_consumed' => true,
            'stock_consumed_at' => now(),
        ]);
    }

    public function reservedQuantitiesByProduct(Requisition $requisition): array
    {
        // Reopening releases reservations against the requisition, while item
        // creation reserves against the item. Both origins belong to one balance.
        return StockMovement::withoutGlobalScope(RoleRecordScope::class)
            ->where('company_id', $requisition->company_id)
            ->where(function ($query) use ($requisition) {
                $query->where(fn ($query) => $query->where('source_type', 'requisition')->where('source_id', $requisition->id))
                    ->orWhere(fn ($query) => $query->where('source_type', 'requisition_item')->whereIn('source_id', $requisition->items()->select('id')));
            })
            ->whereIn('type', [Type::RESERVATION->value, Type::RESERVATION_RELEASE->value])
            ->get(['product_id', 'type', 'quantity', 'base_quantity'])
            ->groupBy('product_id')
            ->map(fn ($movements) => max(0, (float) $movements->sum(
                fn (StockMovement $movement) => $movement->type === Type::RESERVATION
                    ? $movement->resolvedBaseQuantity()
                    : -$movement->resolvedBaseQuantity()
            )))
            ->all();
    }

    public function syncConsumptionFlags(Requisition $requisition): void
    {
        $controlled = $requisition->items()->where(fn ($query) => $query->where('stock_control_snapshot', true)->orWhere(fn ($query) => $query->whereNull('stock_control_snapshot')->whereHas('product', fn ($query) => $query->where('has_stock_control', true))));
        $hasControlled = $requisition->stock_control_enabled !== false && $controlled->exists();
        $hasPendingItems = $hasControlled && (clone $controlled)->whereNull('stock_consumed_at')->exists();

        $requisition->update([
            'stock_consumed' => $hasControlled && ! $hasPendingItems,
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
        ?float $baseQuantity = null,
    ): StockMovement {
        $productStock = $this->resolveProductStock($requisition, $item);
        $product = $item->product;

        if ($product === null) {
            throw new RuntimeException('Produto não encontrado para recriar reserva de estoque.');
        }

        $requestedBaseQuantity = $item->resolvedBaseQuantity();
        $reservedBaseQuantity = $baseQuantity ?? $requestedBaseQuantity;
        $conversionFactor = (float) $item->quantity > 0 && $requestedBaseQuantity > 0
            ? $requestedBaseQuantity / (float) $item->quantity
            : 1;

        $movement = $this->stockMovementService->create(array_merge(
            $this->movementBaseData($requisition, $item, $productStock, $sourceType, $sourceId),
            [
                'type' => Type::RESERVATION->value,
                'base_unit' => $product->unit?->value,
                'base_quantity' => $reservedBaseQuantity,
                'quantity' => $reservedBaseQuantity,
                'operational_quantity' => $reservedBaseQuantity / $conversionFactor,
                'conversion_factor_snapshot' => $conversionFactor,
                'reason' => $reason,
            ]
        ), $userId);

        if (! $movement) {
            throw new RuntimeException(
                'Falha ao recriar reserva de estoque para produto #'.$item->product_id
                .': '.(collect($this->stockMovementService->getErrors())->flatten()->implode(' ') ?: $this->stockMovementService->getMessage())
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
                'Falha ao liberar reserva de estoque para produto #'.$item->product_id
                .': '.$this->stockMovementService->getMessage()
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
                'Falha ao criar saída de estoque para produto #'.$item->product_id
                .': '.$this->stockMovementService->getMessage()
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
            throw new RuntimeException('Estoque não encontrado para o produto #'.$item->product_id);
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
