<?php

namespace App\Services\StockMovement;

use App\Models\StockMovement;
use App\Services\StockMovement\Actions\CreateStockMovementAction;
use App\Services\StockMovement\Actions\DeleteStockMovementAction;
use App\Services\StockMovement\Actions\UpdateStockMovementAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockMovementService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Consultas
     |==============================*/

    /**
     * Lista todas as movimentacoes de estoque de uma empresa.
     *
     * @param int $companyId
     * @param array $filters
     * @return Collection
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        Log::debug('StockMovementService: Listando movimentacoes de estoque', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'company_id' => $companyId,
            'filters'    => $filters,
        ]);

        $query = StockMovement::where('company_id', $companyId);

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query->with([
            'product',
            'productStock',
            'company',
            'user',
            'createdBy',
            'updatedBy',
        ])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Busca uma movimentacao pelo ID.
     *
     * @param int $id
     * @param int|null $companyId
     * @return StockMovement|null
     */
    public function find(int $id, ?int $companyId = null): ?StockMovement
    {
        $query = StockMovement::where('id', $id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->with([
            'product',
            'productStock',
            'company',
            'user',
            'createdBy',
            'updatedBy',
        ])->first();
    }

    /**
     * Lista movimentacoes de um produto especifico.
     *
     * @param int $productId
     * @param int $companyId
     * @return Collection
     */
    public function listByProduct(int $productId, int $companyId): Collection
    {
        return StockMovement::where('product_id', $productId)
            ->where('company_id', $companyId)
            ->with([
                'user',
                'createdBy',
                'updatedBy',
            ])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /* ==============================
     |  Operacoes de Escrita
     |==============================*/

    /**
     * Cria uma nova movimentacao de estoque.
     *
     * @param array $data
     * @param int $createdBy
     * @return StockMovement|null
     */
    public function create(array $data, int $createdBy): ?StockMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateStockMovementAction($createdBy);
                $movement = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'  => __METHOD__ . '@' . __LINE__,
                        'message' => $action->getMessage(),
                        'errors'  => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Movimentacao de estoque criada com sucesso');

                return $movement;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar movimentacao de estoque');

            Log::error('StockMovementService: Erro ao criar movimentacao', [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
                'data'      => $data,
            ]);

            return null;
        }
    }

    /**
     * Atualiza uma movimentacao de estoque.
     *
     * @param StockMovement $movement
     * @param array $data
     * @param int $updatedBy
     * @return StockMovement|null
     */
    public function update(StockMovement $movement, array $data, int $updatedBy): ?StockMovement
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($movement, $data, $updatedBy) {
                $action = new UpdateStockMovementAction($updatedBy);
                $updated = $action->execute($movement, $data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        $action->getErrorCode(),
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'stock_movement_id'  => $movement->id,
                        'message'            => $action->getMessage(),
                        'errors'             => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Movimentacao de estoque atualizada com sucesso');

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar movimentacao de estoque');

            Log::error('StockMovementService: Erro ao atualizar movimentacao', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
                'data'               => $data,
            ]);

            return null;
        }
    }

    /**
     * Exclui uma movimentacao de estoque.
     *
     * @param StockMovement $movement
     * @return bool
     */
    public function delete(StockMovement $movement): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($movement) {
                $action = new DeleteStockMovementAction();
                $result = $action->execute($movement);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        $action->getErrorCode(),
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'stock_movement_id'  => $movement->id,
                        'message'            => $action->getMessage(),
                    ]);

                    return false;
                }

                $this->setSuccess('Movimentacao de estoque excluida com sucesso');

                Log::info('StockMovementService: Movimentacao de estoque excluida com sucesso', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'stock_movement_id'  => $movement->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir movimentacao de estoque');

            Log::error('StockMovementService: Erro ao excluir movimentacao', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente (force delete) uma movimentacao de estoque.
     *
     * @param StockMovement $movement
     * @return bool
     */
    public function forceDelete(StockMovement $movement): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($movement) {
                $action = new DeleteStockMovementAction();
                $result = $action->forceDelete($movement);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        $action->getErrorCode(),
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'stock_movement_id'  => $movement->id,
                        'message'            => $action->getMessage(),
                    ]);

                    return false;
                }

                $this->setSuccess('Movimentacao de estoque removida permanentemente com sucesso');

                Log::info('StockMovementService: Movimentacao removida permanentemente', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'stock_movement_id'  => $movement->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao remover permanentemente movimentacao de estoque');

            Log::error('StockMovementService: Erro ao remover permanentemente', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'stock_movement_id'  => $movement->id,
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
