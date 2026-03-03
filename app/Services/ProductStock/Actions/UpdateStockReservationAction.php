<?php

namespace App\Services\ProductStock\Actions;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Atualiza os campos de reserva do estoque de um produto.
 * Usado quando itens de requisição são criados, atualizados ou removidos.
 *
 * $quantityDelta positivo → reserva mais (criação ou aumento de quantidade)
 * $quantityDelta negativo → libera reserva (exclusão ou redução de quantidade)
 */
class UpdateStockReservationAction
{
    use HandlesActionResponse;

    public function __construct(
        private ProductStock $stock,
        private int          $updatedBy,
    ) {}

    /**
     * @param  float  $quantityDelta  Variação na quantidade reservada (+/-)
     * @param  float  $lastSalePrice  Último preço de venda registrado no item
     * @param  Type $movementType   Tipo do movimento (ex: 'requisition_created', 'requisition_updated', 'requisition_deleted')
     */
    public function execute(float $quantityDelta, float $lastSalePrice, Type $movementType): bool
    {
        try {
            Log::debug('UpdateStockReservationAction: Atualizando reserva de estoque', [
                'metodo'          => __METHOD__ . '@' . __LINE__,
                'stock_id'        => $this->stock->id,
                'product_id'      => $this->stock->product_id,
                'quantity_delta'  => $quantityDelta,
                'movement_type'   => $movementType,
                'user_id'         => $this->updatedBy,
            ]);

            $newReserved = max(0, (float) $this->stock->quantity_reserved + $quantityDelta);

            $this->stock->update([
                'quantity_reserved'  => $newReserved,
                'last_sale_price'    => $lastSalePrice,
                'last_movement_date' => now(),
                'last_movement_type' => $movementType,
                'updated_by'         => $this->updatedBy,
            ]);

            Log::info('UpdateStockReservationAction: Reserva atualizada com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_id'           => $this->stock->id,
                'product_id'         => $this->stock->product_id,
                'quantity_reserved'  => $newReserved,
                'movement_type'      => $movementType,
            ]);

            $this->setSuccess();
            return true;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar reserva de estoque');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'stock_id'      => $this->stock->id,
                'product_id'    => $this->stock->product_id,
                'message_error' => $e->getMessage(),
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar reserva de estoque');

            Log::error($this->getMessage(), [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'stock_id'      => $this->stock->id,
                'product_id'    => $this->stock->product_id,
                'message_error' => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
