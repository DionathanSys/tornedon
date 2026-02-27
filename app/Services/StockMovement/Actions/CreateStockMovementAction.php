<?php

namespace App\Services\StockMovement\Actions;

use App\Models\StockMovement;
use App\Services\StockMovement\Validators\StockMovementValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateStockMovementAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria uma nova movimentação de estoque.
     *
     * @param array $data
     * @return StockMovement|null
     */
    public function execute(array $data): ?StockMovement
    {
        try {
            Log::debug('CreateStockMovementAction: Iniciando criação de movimentação', [
                'metodo'  => __METHOD__ . '@' . __LINE__,
                'user_id' => $this->createdBy,
                'data'    => $data,
            ]);

            $validated = StockMovementValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;

            $movement = StockMovement::create($validated);

            $this->setSuccess();

            Log::info('CreateStockMovementAction: Movimentação de estoque criada com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'product_id'         => $movement->product_id,
                'type'               => $movement->type->value,
                'quantity'           => $movement->quantity,
            ]);

            return $movement;
        } catch (ValidationException $e) {
            $this->setError('Erro de validação', $e->errors());

            Log::error('CreateStockMovementAction: ' . $this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors' => $e->errors(),
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao criar movimentação de estoque', [], 422);

            Log::error('CreateStockMovementAction: ' . $this->getMessage(), [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar movimentação', [], 500);

            Log::error('CreateStockMovementAction: ' . $this->getMessage(), [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
