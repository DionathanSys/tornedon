<?php

namespace App\Services\StockMovement\Actions;

use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteStockMovementAction
{
    use HandlesActionResponse;

    /**
     * Exclui uma movimentacao e recalcula o estoque.
     */
    public function execute(StockMovement $movement): bool
    {
        try {
            Log::debug('DeleteStockMovementAction: Iniciando exclusao de movimentacao', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            $stock = ProductStock::where('id', $movement->product_stock_id)
                ->lockForUpdate()
                ->first();

            // Exclui primeiro para que o recalculo ignore este movimento.
            $movement->delete();

            if ($stock) {
                (new RecalculateProductStockFromMovementsAction())->recalculate($stock);
            }

            $this->setSuccess();

            Log::info('DeleteStockMovementAction: Movimentacao de estoque excluida com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            return true;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir movimentacao de estoque', [], 422);

            Log::error('DeleteStockMovementAction: Erro ao excluir movimentacao', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir movimentacao', [], 500);

            Log::error('DeleteStockMovementAction: Erro inesperado', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente (force delete) uma movimentacao e recalcula o estoque.
     */
    public function forceDelete(StockMovement $movement): bool
    {
        try {
            Log::debug('DeleteStockMovementAction: Iniciando exclusao permanente', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            $stock = ProductStock::where('id', $movement->product_stock_id)
                ->lockForUpdate()
                ->first();

            // Force delete primeiro para que o recalculo ignore este movimento.
            $movement->forceDelete();

            if ($stock) {
                (new RecalculateProductStockFromMovementsAction())->recalculate($stock);
            }

            $this->setSuccess();

            Log::info('DeleteStockMovementAction: Movimentacao excluida permanentemente com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            return true;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir permanentemente movimentacao', [], 422);

            Log::error('DeleteStockMovementAction: Erro ao excluir permanentemente', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente', [], 500);

            Log::error('DeleteStockMovementAction: Erro inesperado ao excluir permanentemente', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
