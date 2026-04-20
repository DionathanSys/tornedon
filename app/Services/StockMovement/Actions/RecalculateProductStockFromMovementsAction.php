<?php

namespace App\Services\StockMovement\Actions;

use App\Models\ProductStock;
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
        $result = app(CalculateProductStockSnapshotAction::class)->calculate($stock);

        $stock->update($result);

        Log::debug('RecalculateProductStockFromMovementsAction: Estoque recalculado', [
            'metodo'           => __METHOD__ . '@' . __LINE__,
            'product_stock_id' => $stock->id,
            'result'           => $result,
        ]);

        return $result;
    }
}
