<?php

namespace App\Services\StockMovement\Actions;

use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\Access\RoleRecordScope;

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
            // A valorização não pode depender dos movimentos visíveis ao operador.
            ->withoutGlobalScope(RoleRecordScope::class)
            ->where('product_stock_id', $stock->id)
            ->where('company_id', $stock->company_id)
            ->where('product_id', $stock->product_id)
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $calculator = app(CalculateStockMovementStateAction::class);
        $state = $stock->additional_info['movement_opening_snapshot'] ?? null;

        // Sem histórico nem abertura registrada, não há fonte para substituir o saldo.
        if ($state === null && $movements->isEmpty()) {
            return $calculator->fromStock($stock);
        }

        $state ??= $calculator->emptyState();

        foreach ($movements as $movement) {
            $state = $calculator->calculate($state, $movement);
        }

        return $state;
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
                'stored' => $stored,
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
                'stored' => $stored,
                'expected' => $expected,
            ];
        }
    }
}
