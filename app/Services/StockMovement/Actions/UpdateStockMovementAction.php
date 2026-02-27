<?php

namespace App\Services\StockMovement\Actions;

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
     * Atualiza uma movimentação de estoque.
     *
     * @param StockMovement $movement
     * @param array $data
     * @return StockMovement|null
     */
    public function execute(StockMovement $movement, array $data): ?StockMovement
    {
        try {
            Log::debug('UpdateStockMovementAction: Iniciando atualização de movimentação', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'user_id'            => $this->updatedBy,
                'data'               => $data,
            ]);

            $validated = StockMovementValidator::validateUpdate($data);
            $validated['updated_by'] = $this->updatedBy;

            $movement->update($validated);
            $movement->refresh();

            $this->setSuccess();

            Log::info('UpdateStockMovementAction: Movimentação de estoque atualizada com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'product_id'         => $movement->product_id,
            ]);

            return $movement;
        } catch (ValidationException $e) {
            $this->setError('Erro de validação', $e->errors);

            Log::error('UpdateStockMovementAction: Erro de validação', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'errors'             => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar movimentação de estoque', [], 422);

            Log::error('UpdateStockMovementAction: Erro ao atualizar movimentação', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar movimentação', [], 500);

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
