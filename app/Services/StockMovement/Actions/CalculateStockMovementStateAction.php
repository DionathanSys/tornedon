<?php

namespace App\Services\StockMovement\Actions;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\StockMovement;

/** Regra única de evolução, sem persistência nem validação do saldo operacional. */
class CalculateStockMovementStateAction
{
    public function fromStock(ProductStock $stock): array
    {
        return [
            'quantity_total' => (float) $stock->quantity_total,
            'quantity_reserved' => (float) $stock->quantity_reserved,
            'average_cost' => (float) $stock->average_cost,
            'last_cost' => $stock->last_cost !== null ? (float) $stock->last_cost : null,
            'last_sale_price' => $stock->last_sale_price !== null ? (float) $stock->last_sale_price : null,
            'last_movement_date' => $stock->last_movement_date?->toDateString(),
            'last_movement_type' => $stock->last_movement_type?->value,
        ];
    }

    public function emptyState(): array
    {
        return [
            'quantity_total' => 0.0,
            'quantity_reserved' => 0.0,
            'average_cost' => 0.0,
            'last_cost' => null,
            'last_sale_price' => null,
            'last_movement_date' => null,
            'last_movement_type' => null,
        ];
    }

    public function calculate(array $state, StockMovement $movement, bool $reverse = false): array
    {
        $type = $movement->type;
        $quantity = $movement->resolvedBaseQuantity();
        $unitPrice = $movement->unit_price !== null ? (float) $movement->unit_price : null;

        if ($type->isReservationType()) {
            $delta = $type->applyReservationDelta($quantity) * ($reverse ? -1 : 1);
            $state['quantity_reserved'] = round(max(0, $state['quantity_reserved'] + $delta), 3);
        } else {
            $delta = $type->applyDelta($quantity) * ($reverse ? -1 : 1);
            $previousQuantity = $state['quantity_total'];
            $state['quantity_total'] = round($previousQuantity + $delta, 3);

            if (! $reverse && $movement->isAdministrativeCostAdjustment()) {
                $state['average_cost'] = round((float) $movement->additional_info['average_cost'], 4);
                $state['last_cost'] = round((float) $movement->additional_info['last_cost'], 4);
            } elseif (! $reverse && ($type->isInbound() || ($type === Type::ADJUSTMENT && $delta > 0))
                && $unitPrice !== null && $unitPrice > 0) {
                $operationalQuantity = $movement->resolvedOperationalQuantity();
                $baseCost = $quantity > 0 && $operationalQuantity > 0
                    ? round($operationalQuantity * $unitPrice / $quantity, 4)
                    : $unitPrice;

                // Saldo zero/negativo não carrega valor fictício para uma nova compra.
                $state['average_cost'] = $previousQuantity <= 0 || $state['quantity_total'] <= 0
                    ? $baseCost
                    : round(($previousQuantity * $state['average_cost'] + abs($quantity) * $baseCost)
                        / $state['quantity_total'], 4);
                $state['last_cost'] = $baseCost;
            }

            // Preço ausente/zero não redefine custos nem o último preço de venda.
            if (! $reverse && $type->isOutbound() && $unitPrice !== null && $unitPrice > 0) {
                $state['last_sale_price'] = $unitPrice;
            }
        }

        $state['last_movement_date'] = $movement->created_at->toDateString();
        $state['last_movement_type'] = $type->value;

        return $state;
    }
}
