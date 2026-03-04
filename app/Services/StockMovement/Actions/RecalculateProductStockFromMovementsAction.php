<?php

namespace App\Services\StockMovement\Actions;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula os campos de ProductStock do zero, somando todas as movimentações ativas.
 *
 * Deve ser chamado DENTRO de uma transação com o ProductStock já bloqueado via lockForUpdate().
 * Usado em updates e deletes de movimentações, onde o recálculo incremental seria impreciso.
 * Também usado pelo comando de reconciliação.
 */
class RecalculateProductStockFromMovementsAction
{
    /**
     * Recalcula e persiste os campos calculáveis do ProductStock.
     *
     * @param  ProductStock $stock    Registro bloqueado com lockForUpdate()
     * @return array{
     *     quantity_total: float,
     *     average_cost: float,
     *     last_cost: float|null,
     *     last_sale_price: float|null,
     *     last_movement_date: string|null,
     *     last_movement_type: string|null,
     * }
     */
    public function recalculate(ProductStock $stock): array
    {
        // Busca todas as movimentações ativas desta stock, em ordem cronológica
        $movements = StockMovement::where('product_stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $quantityTotal     = 0.0;
        $totalInboundCost  = 0.0;  // numerador para custo médio
        $totalInboundQty   = 0.0;  // denominador para custo médio

        $lastCost          = null;
        $lastSalePrice     = null;
        $lastMovementDate  = null;
        $lastMovementType  = null;

        foreach ($movements as $movement) {
            /** @var Type $type */
            $type      = $movement->type;
            $quantity  = (float) $movement->quantity;
            $unitPrice = $movement->unit_price !== null ? (float) $movement->unit_price : null;

            $delta = $type->applyDelta($quantity);
            $quantityTotal += $delta;

            // Custo médio: acumula apenas entradas com preço
            if ($type->isInbound() && $unitPrice !== null && $unitPrice > 0) {
                $totalInboundQty  += abs($quantity);
                $totalInboundCost += abs($quantity) * $unitPrice;
                $lastCost = $unitPrice;
            } elseif ($type === Type::ADJUSTMENT && $delta > 0 && $unitPrice !== null && $unitPrice > 0) {
                $totalInboundQty  += $delta;
                $totalInboundCost += $delta * $unitPrice;
                $lastCost = $unitPrice;
            }

            // Último preço de venda
            if ($type->isOutbound() && $unitPrice !== null && $unitPrice > 0) {
                $lastSalePrice = $unitPrice;
            }

            $lastMovementDate = $movement->created_at->toDateString();
            $lastMovementType = $type->value;
        }

        $averageCost = $totalInboundQty > 0
            ? round($totalInboundCost / $totalInboundQty, 4)
            : (float) $stock->average_cost;  // mantém o custo existente se não houve entradas

        $result = [
            'quantity_total'     => round($quantityTotal, 3),
            'average_cost'       => $averageCost,
            'last_cost'          => $lastCost,
            'last_sale_price'    => $lastSalePrice,
            'last_movement_date' => $lastMovementDate,
            'last_movement_type' => $lastMovementType,
        ];

        $stock->update($result);

        Log::debug('RecalculateProductStockFromMovementsAction: Estoque recalculado', [
            'metodo'           => __METHOD__ . '@' . __LINE__,
            'product_stock_id' => $stock->id,
            'movements_count'  => $movements->count(),
            'result'           => $result,
        ]);

        return $result;
    }
}
