<?php

namespace App\Services\StockMovement\Actions;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Log;

class CalculateProductStockSnapshotAction
{
    public const TOLERANCE = 0.001;

    /**
     * @return array{
     *     quantity_total: float,
     *     quantity_reserved: float,
     *     average_cost: float,
     *     last_cost: float|null,
     *     last_sale_price: float|null,
     *     last_movement_date: string|null,
     *     last_movement_type: string|null,
     * }
     */
    public function calculate(ProductStock $stock): array
    {
        $movements = StockMovement::query()
            ->where('product_stock_id', $stock->id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $quantityTotal    = 0.0;
        $quantityReserved = 0.0;
        $totalInboundCost = 0.0;
        $totalInboundQty  = 0.0;

        $lastCost         = null;
        $lastSalePrice    = null;
        $lastMovementDate = null;
        $lastMovementType = null;

        foreach ($movements as $movement) {
            /** @var Type $type */
            $type      = $movement->type;
            $quantity  = (float) $movement->quantity;
            $unitPrice = $movement->unit_price !== null ? (float) $movement->unit_price : null;

            if ($type->isReservationType()) {
                $quantityReserved += $type->applyReservationDelta($quantity);
            } else {
                $delta = $type->applyDelta($quantity);
                $quantityTotal += $delta;

                if ($type->isInbound() && $unitPrice !== null && $unitPrice > 0) {
                    $totalInboundQty  += abs($quantity);
                    $totalInboundCost += abs($quantity) * $unitPrice;
                    $lastCost = $unitPrice;
                } elseif ($type === Type::ADJUSTMENT && $delta > 0 && $unitPrice !== null && $unitPrice > 0) {
                    $totalInboundQty  += $delta;
                    $totalInboundCost += $delta * $unitPrice;
                    $lastCost = $unitPrice;
                }

                if ($type->isOutbound() && $unitPrice !== null && $unitPrice > 0) {
                    $lastSalePrice = $unitPrice;
                }
            }

            $lastMovementDate = $movement->created_at->toDateString();
            $lastMovementType = $type->value;
        }

        if ($quantityReserved < 0) {
            Log::warning('CalculateProductStockSnapshotAction: Reserva liquida negativa detectada, valor ajustado para zero', [
                'product_stock_id' => $stock->id,
                'reserved_raw'     => $quantityReserved,
            ]);

            $quantityReserved = 0.0;
        }

        $averageCost = $totalInboundQty > 0
            ? round($totalInboundCost / $totalInboundQty, 4)
            : round((float) $stock->average_cost, 4);

        return [
            'quantity_total'     => round($quantityTotal, 3),
            'quantity_reserved'  => round($quantityReserved, 3),
            'average_cost'       => $averageCost,
            'last_cost'          => $lastCost !== null ? round($lastCost, 4) : null,
            'last_sale_price'    => $lastSalePrice !== null ? round($lastSalePrice, 4) : null,
            'last_movement_date' => $lastMovementDate,
            'last_movement_type' => $lastMovementType,
        ];
    }

    /**
     * @return array<string, array{stored:mixed, expected:mixed}>
     */
    public function diff(ProductStock $stock, array $expected, float $tolerance = self::TOLERANCE): array
    {
        $diff = [];

        $this->compareDecimal($diff, 'quantity_total', (float) $stock->quantity_total, (float) $expected['quantity_total'], 3, $tolerance);
        $this->compareDecimal($diff, 'quantity_reserved', (float) $stock->quantity_reserved, (float) $expected['quantity_reserved'], 3, $tolerance);
        $this->compareDecimal($diff, 'average_cost', (float) $stock->average_cost, (float) $expected['average_cost'], 4, $tolerance);
        $this->compareNullableDecimal($diff, 'last_cost', $stock->last_cost, $expected['last_cost'], 4, $tolerance);
        $this->compareNullableDecimal($diff, 'last_sale_price', $stock->last_sale_price, $expected['last_sale_price'], 4, $tolerance);
        $this->compareScalar($diff, 'last_movement_date', $stock->last_movement_date?->toDateString(), $expected['last_movement_date']);
        $this->compareScalar($diff, 'last_movement_type', $stock->last_movement_type?->value ?? $stock->getRawOriginal('last_movement_type'), $expected['last_movement_type']);

        return $diff;
    }

    /**
     * @param  array<string, array{stored:mixed, expected:mixed}>  $diff
     */
    private function compareDecimal(array &$diff, string $field, float $stored, float $expected, int $precision, float $tolerance): void
    {
        $stored = round($stored, $precision);
        $expected = round($expected, $precision);

        if (abs($stored - $expected) > $tolerance) {
            $diff[$field] = [
                'stored'   => $stored,
                'expected' => $expected,
            ];
        }
    }

    /**
     * @param  array<string, array{stored:mixed, expected:mixed}>  $diff
     */
    private function compareNullableDecimal(array &$diff, string $field, mixed $stored, mixed $expected, int $precision, float $tolerance): void
    {
        if ($stored === null || $expected === null) {
            $this->compareScalar(
                $diff,
                $field,
                $stored !== null ? round((float) $stored, $precision) : null,
                $expected !== null ? round((float) $expected, $precision) : null,
            );

            return;
        }

        $this->compareDecimal($diff, $field, (float) $stored, (float) $expected, $precision, $tolerance);
    }

    /**
     * @param  array<string, array{stored:mixed, expected:mixed}>  $diff
     */
    private function compareScalar(array &$diff, string $field, mixed $stored, mixed $expected): void
    {
        if ($stored !== $expected) {
            $diff[$field] = [
                'stored'   => $stored,
                'expected' => $expected,
            ];
        }
    }
}
