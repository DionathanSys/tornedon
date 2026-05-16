<?php

namespace App\Services\Requisition;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class RequisitionStockService
{
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
}
