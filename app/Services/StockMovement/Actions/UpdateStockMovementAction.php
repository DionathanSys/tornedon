<?php

namespace App\Services\StockMovement\Actions;

use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Services\StockMovement\Validators\StockMovementValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateStockMovementAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
    ) {}

    /**
     * Atualiza uma movimenta��o de estoque e recalcula o ProductStock do zero.
     *
     * @param StockMovement $movement
     * @param array $data
     * @return StockMovement|null
     */
    public function execute(StockMovement $movement, array $data): ?StockMovement
    {
        try {
            Log::debug('UpdateStockMovementAction: Iniciando atualiza��o de movimenta��o', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'user_id'            => $this->updatedBy,
                'data'               => $data,
            ]);

            $validated = StockMovementValidator::validateUpdate($data);
            $validated['updated_by'] = $this->updatedBy;

            // Bloqueia o ProductStock antes de qualquer altera��o
            $stock = ProductStock::where('id', $movement->product_stock_id)
                ->lockForUpdate()
                ->first();

            if (!$stock) {
                $this->setError('Registro de estoque n�o encontrado para rec�lculo', [], 422);
                return null;
            }

            // Atualiza o registro da movimenta��o
            $movement->update($validated);
            $movement->refresh();

            // Recalcula o estoque do zero a partir de todas as movimenta��es ativas
            (new RecalculateProductStockFromMovementsAction())->recalculate($stock);

            $this->setSuccess();

            Log::info('UpdateStockMovementAction: Movimenta��o de estoque atualizada com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'product_id'         => $movement->product_id,
            ]);

            return $movement;
        } catch (ValidationException $e) {
            $this->setError('Erro de valida��o', $e->errors());

            Log::error('UpdateStockMovementAction: Erro de valida��o', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'errors'             => $e->errors(),
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar movimenta��o de estoque', [], 422);

            Log::error('UpdateStockMovementAction: Erro ao atualizar movimenta��o', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar movimenta��o', [], 500);

            Log::error('UpdateStockMovementAction: Erro inesperado', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
