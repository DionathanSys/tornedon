<?php

namespace App\Services\StockMovement\Actions;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Aplica o efeito de uma movimentação ao ProductStock de forma incremental.
 *
 * Deve ser chamado DENTRO de uma transação com o ProductStock já bloqueado via lockForUpdate().
 * Não lança exceções — retorna bool.
 */
class ApplyMovementToProductStockAction
{
    /**
     * @param  ProductStock  $stock  Registro bloqueado com lockForUpdate()
     * @param  StockMovement  $movement  Movimentação recém-criada (com type, quantity, unit_price, total_amount)
     * @param  bool  $reverse  Se true, reverte o efeito (usado em delete/update)
     */
    public function apply(ProductStock $stock, StockMovement $movement, bool $reverse = false): bool
    {
        /** @var Type $type */
        $type = $movement->type;

        // Tipos de reserva apenas afetam quantity_reserved — não tocam em quantity_total
        if ($type->isReservationType()) {
            return $this->applyReservation($stock, $movement, $reverse);
        }

        $quantity = $movement->resolvedBaseQuantity();
        $unitPrice = $movement->unit_price !== null ? (float) $movement->unit_price : null;
        $baseUnitCost = $this->resolveBaseUnitCost($movement, $unitPrice);

        // Calcula o delta positivo/negativo segundo o tipo de movimento
        $delta = $type->applyDelta($quantity);

        // Se for reversão, inverte o delta
        if ($reverse) {
            $delta = -$delta;
        }

        $currentQty = (float) $stock->quantity_total;
        $currentAvg = (float) $stock->average_cost;
        $newQty = round($currentQty + $delta, 3);

        // Garante que não fique negativo se allow_negative === false
        if (! $stock->allow_negative && $newQty < 0) {
            Log::warning('ApplyMovementToProductStockAction: Quantidade resultante negativa bloqueada', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'product_stock_id' => $stock->id,
                'current_qty' => $currentQty,
                'delta' => $delta,
                'new_qty' => $newQty,
                'movement_id' => $movement->id,
            ]);
            throw ValidationException::withMessages(['quantity' => 'Saldo físico insuficiente; o estoque não permite negativo.']);
        }
        if (! $stock->allow_negative && $delta < 0 && $newQty + 0.0001 < (float) $stock->quantity_reserved) {
            throw ValidationException::withMessages(['quantity' => 'A saída não pode consumir o saldo reservado para outras operações.']);
        }

        $updates = [
            'quantity_total' => $newQty,
            'last_movement_date' => now()->toDateString(),
            'last_movement_type' => $type->value,
        ];

        // — Custo médio ponderado (somente em entradas reais sem reversão, ou reversão de saídas) —
        if (! $reverse && $type->isInbound() && $baseUnitCost !== null && $baseUnitCost > 0) {
            // Entrada: recalcula custo médio ponderado
            $newAvgCost = $this->recalcWeightedAvgCost($currentQty, $currentAvg, abs($quantity), $baseUnitCost);
            $updates['average_cost'] = $newAvgCost;
            $updates['last_cost'] = $baseUnitCost;
        } elseif (! $reverse && $type === Type::ADJUSTMENT && $delta > 0 && $baseUnitCost !== null && $baseUnitCost > 0) {
            // Ajuste positivo com preço informado: recalcula custo médio
            $newAvgCost = $this->recalcWeightedAvgCost($currentQty, $currentAvg, $delta, $baseUnitCost);
            $updates['average_cost'] = $newAvgCost;
            $updates['last_cost'] = $baseUnitCost;
        }

        // — Último preço de venda (somente em saídas que possuem unit_price) —
        if (! $reverse && $type->isOutbound() && $unitPrice !== null && $unitPrice > 0) {
            $updates['last_sale_price'] = $unitPrice;
        }

        $stock->update($updates);

        Log::debug('ApplyMovementToProductStockAction: Estoque atualizado', [
            'metodo' => __METHOD__.'@'.__LINE__,
            'product_stock_id' => $stock->id,
            'reverse' => $reverse,
            'type' => $type->value,
            'delta' => $delta,
            'qty_total_before' => $currentQty,
            'qty_total_after' => $newQty,
            'avg_before' => $currentAvg,
            'avg_after' => $updates['average_cost'] ?? $currentAvg,
        ]);

        return true;
    }

    /**
     * Custo médio ponderado: ((qtd_atual * custo_atual) + (qtd_entrada * custo_entrada)) / qtd_total.
     */
    private function recalcWeightedAvgCost(
        float $currentQty,
        float $currentAvg,
        float $incomingQty,
        float $incomingCost,
    ): float {
        $totalQty = $currentQty + $incomingQty;

        if ($totalQty <= 0) {
            return $incomingCost;
        }

        return round(
            (($currentQty * $currentAvg) + ($incomingQty * $incomingCost)) / $totalQty,
            4
        );
    }

    private function resolveBaseUnitCost(StockMovement $movement, ?float $operationalUnitPrice): ?float
    {
        if ($operationalUnitPrice === null || $operationalUnitPrice <= 0) {
            return null;
        }

        $operationalQuantity = $movement->resolvedOperationalQuantity();
        $baseQuantity = $movement->resolvedBaseQuantity();

        if ($operationalQuantity <= 0 || $baseQuantity <= 0) {
            return $operationalUnitPrice;
        }

        return round(($operationalQuantity * $operationalUnitPrice) / $baseQuantity, 4);
    }

    /**
     * Aplica um movimento de reserva/liberação ao quantity_reserved do ProductStock.
     * Não altera quantity_total nem custo médio.
     */
    private function applyReservation(ProductStock $stock, StockMovement $movement, bool $reverse): bool
    {
        $type = $movement->type;
        $quantity = $movement->resolvedBaseQuantity();

        $delta = $type->applyReservationDelta($quantity);

        if ($reverse) {
            $delta = -$delta;
        }

        $newReserved = max(0, (float) $stock->quantity_reserved + $delta);
        if (! $stock->allow_negative && $delta > 0 && $newReserved > (float) $stock->quantity_total + 0.0001) {
            throw ValidationException::withMessages(['quantity' => 'Saldo disponível insuficiente para reservar; o estoque não permite negativo.']);
        }

        $stock->update([
            'quantity_reserved' => $newReserved,
            'last_movement_date' => now()->toDateString(),
            'last_movement_type' => $type->value,
        ]);

        Log::debug('ApplyMovementToProductStockAction: Reserva atualizada', [
            'metodo' => __METHOD__.'@'.__LINE__,
            'product_stock_id' => $stock->id,
            'type' => $type->value,
            'reverse' => $reverse,
            'delta' => $delta,
            'reserved_before' => (float) $stock->getOriginal('quantity_reserved'),
            'reserved_after' => $newReserved,
        ]);

        return true;
    }
}
