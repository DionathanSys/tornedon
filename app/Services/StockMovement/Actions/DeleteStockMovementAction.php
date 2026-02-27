<?php

namespace App\Services\StockMovement\Actions;

use App\Models\StockMovement;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteStockMovementAction
{
    use HandlesActionResponse;

    /**
     * Exclui (soft delete) uma movimentação de estoque.
     *
     * @param StockMovement $movement
     * @return bool
     */
    public function execute(StockMovement $movement): bool
    {
        try {
            Log::debug('DeleteStockMovementAction: Iniciando exclusão de movimentação', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            $movement->delete();

            $this->setSuccess();

            Log::info('DeleteStockMovementAction: Movimentação de estoque excluída com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            return true;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir movimentação de estoque', [], 422);

            Log::error('DeleteStockMovementAction: Erro ao excluir movimentação', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir movimentação', [], 500);

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
     * Exclui permanentemente (force delete) uma movimentação de estoque.
     *
     * @param StockMovement $movement
     * @return bool
     */
    public function forceDelete(StockMovement $movement): bool
    {
        try {
            Log::debug('DeleteStockMovementAction: Iniciando exclusão permanente', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            $movement->forceDelete();

            $this->setSuccess();

            Log::info('DeleteStockMovementAction: Movimentação excluída permanentemente com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
            ]);

            return true;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir permanentemente movimentação', [], 422);

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
