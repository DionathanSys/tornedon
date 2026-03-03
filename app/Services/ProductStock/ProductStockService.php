<?php

namespace App\Services\ProductStock;

use App\Enum\StockMovement\Type;
use App\Models\ProductStock;
use App\Services\ProductStock\Actions\CreateProductStockAction;
use App\Services\ProductStock\Actions\DeleteProductStockAction;
use App\Services\ProductStock\Actions\RestoreProductStockAction;
use App\Services\ProductStock\Actions\UpdateProductStockAction;
use App\Services\ProductStock\Actions\UpdateStockReservationAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductStockService
{
    use HandlesServiceResponse;

    /**
     * Lista todos os estoques de produtos de uma empresa.
     *
     * @param int $companyId
     * @param array $filters
     * @return Collection
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        $query = ProductStock::where('company_id', $companyId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        return $query->with(['product', 'company'])->get();
    }

    /**
     * Lista estoques de produtos com paginação.
     *
     * @param int $companyId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginate(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = ProductStock::where('company_id', $companyId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%");
            });
        }

        return $query->with(['product', 'company'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Busca um estoque de produto pelo ID.
     *
     * @param int $id
     * @param int|null $companyId
     * @return ProductStock|null
     */
    public function find(int $id, ?int $companyId = null): ?ProductStock
    {
        $query = ProductStock::where('id', $id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->with(['product', 'company'])->first();
    }

    /**
     * Busca um estoque de produto pelo ID do produto.
     *
     * @param int $productId
     * @param int $companyId
     * @return ProductStock|null
     */
    public function findByProductId(int $productId, int $companyId): ?ProductStock
    {
        return ProductStock::where('product_id', $productId)
            ->where('company_id', $companyId)
            ->with(['product', 'company'])
            ->first();
    }

    /**
     * Cria um novo registro de estoque de produto.
     *
     * @param array $data
     * @param int $createdBy
     * @return ProductStock|null
     */
    public function create(array $data, int $createdBy): ?ProductStock
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateProductStockAction($createdBy);
                $productStock = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'action_message'    => $action->getMessage(),
                        'errors'            => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Estoque criado com sucesso');
                return $productStock;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar criação do estoque');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);

            return null;
        }
    }

    /**
     * Atualiza um estoque de produto existente.
     *
     * @param ProductStock $productStock
     * @param array $data
     * @param int $updatedBy
     * @param int|null $companyId
     * @return ProductStock|null
     */
    public function update(ProductStock $productStock, array $data, int $updatedBy, ?int $companyId = null): ?ProductStock
    {
        $this->resetResponse();

        // Valida se o registro pertence à mesma company (se companyId fornecido)
        if ($companyId !== null && !$this->validateCompanyAccess($productStock, $companyId)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($productStock, $data, $updatedBy) {
                $action = new UpdateProductStockAction($updatedBy, $productStock);
                $result = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'errors'            => $action->getErrors(),
                        'product_stock_id'  => $productStock->id,
                    ]);

                    return null;
                }

                $this->setSuccess('Estoque atualizado com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar atualização do estoque');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_stock_id'  => $productStock->id,
                'data'              => $data,
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) um estoque de produto.
     *
     * @param ProductStock $productStock
     * @param int|null $companyId
     * @return bool
     */
    public function delete(ProductStock $productStock, ?int $companyId = null): bool
    {
        $this->resetResponse();

        // Valida se o registro pertence à mesma company (se companyId fornecido)
        if ($companyId !== null && !$this->validateCompanyAccess($productStock, $companyId)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($productStock) {
                $action = new DeleteProductStockAction($productStock);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'action_message'    => $action->getMessage(),
                        'errors'            => $action->getErrors(),
                        'product_stock_id'  => $productStock->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Estoque excluído com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão do estoque');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_stock_id'  => $productStock->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um estoque de produto.
     *
     * @param ProductStock $productStock
     * @param int|null $companyId
     * @return bool
     */
    public function forceDelete(ProductStock $productStock, ?int $companyId = null): bool
    {
        $this->resetResponse();

        // Valida se o registro pertence à mesma company (se companyId fornecido)
        if ($companyId !== null && !$this->validateCompanyAccess($productStock, $companyId)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($productStock) {
                $action = new DeleteProductStockAction($productStock);
                $result = $action->forceDelete();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'action_message'    => $action->getMessage(),
                        'errors'            => $action->getErrors(),
                        'product_stock_id'  => $productStock->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Estoque excluído permanentemente com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão permanente do estoque');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_stock_id'  => $productStock->id,
            ]);

            return false;
        }
    }

    /**
     * Restaura um estoque de produto excluído.
     *
     * @param ProductStock $productStock
     * @param int|null $companyId
     * @return bool
     */
    public function restore(ProductStock $productStock, ?int $companyId = null): bool
    {
        $this->resetResponse();

        // Valida se o registro pertence à mesma company (se companyId fornecido)
        if ($companyId !== null && !$this->validateCompanyAccess($productStock, $companyId)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($productStock) {
                $action = new RestoreProductStockAction($productStock);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'action_message'    => $action->getMessage(),
                        'errors'            => $action->getErrors(),
                        'product_stock_id'  => $productStock->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Estoque restaurado com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar restauração do estoque');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_stock_id'  => $productStock->id,
            ]);

            return false;
        }
    }

    /**
     * Ativa ou desativa um estoque de produto.
     *
     * @param ProductStock $productStock
     * @param bool $active
     * @param int $updatedBy
     * @param int $companyId
     * @return ProductStock|null
     */
    public function toggleActive(ProductStock $productStock, bool $active, int $updatedBy, int $companyId): ?ProductStock
    {
        // Valida se o registro pertence à mesma company
        if (!$this->validateCompanyAccess($productStock, $companyId)) {
            return null;
        }

        return $this->update($productStock, ['is_active' => $active], $updatedBy, $companyId);
    }

    /**
     * Atualiza os campos de reserva de estoque (quantity_reserved, last_sale_price, etc.).
     * Usado pelos listeners de RequisitionItem para reservar/liberar estoque.
     *
     * @param  ProductStock $stock
     * @param  float        $quantityDelta  Variação (+/-) na quantidade reservada
     * @param  float        $lastSalePrice  Último preço de venda praticado no item
     * @param  Type         $movementType   Tipo do movimento para auditoria
     * @param  int          $updatedBy
     * @return bool
     */
    public function updateReservation(
        ProductStock $stock,
        float        $quantityDelta,
        float        $lastSalePrice,
        Type       $movementType,
        int          $updatedBy,
    ): bool {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($stock, $quantityDelta, $lastSalePrice, $movementType, $updatedBy) {
                // Busca o registro de estoque com lock para garantir exclusividade
                $lockedStock = ProductStock::where('id', $stock->id)->lockForUpdate()->first();
                if (! $lockedStock) {
                    $this->setError('Registro de estoque não encontrado para atualização');
                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'stock_id'   => $stock->id,
                        'product_id' => $stock->product_id,
                    ]);
                    return false;
                }

                $action = new UpdateStockReservationAction($lockedStock, $updatedBy);
                $result = $action->execute($quantityDelta, $lastSalePrice, $movementType);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'stock_id'   => $stock->id,
                        'product_id' => $stock->product_id,
                        'delta'      => $quantityDelta,
                    ]);

                    return false;
                }

                $this->setSuccess('Reserva de estoque atualizada com sucesso');
                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar reserva de estoque');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'stock_id'   => $stock->id,
                'product_id' => $stock->product_id,
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Verifica se há saldo líquido disponível para um produto em uma empresa.
     * Saldo líquido = quantity_available - quantity_reserved.
     *
     * Retorna false (sem estoque) somente quando há controle de estoque,
     * allow_negative é false e o saldo líquido está abaixo da quantidade solicitada.
     *
     * @param int   $productId
     * @param int   $companyId
     * @param float $requestedQuantity
     * @return bool
     */
    public function hasNetAvailableStock(int $productId, int $companyId, float $requestedQuantity): bool
    {
        $productStock = $this->findByProductId($productId, $companyId);

        if (! $productStock || $productStock->allow_negative) {
            return true;
        }

        $netAvailable = (float) $productStock->quantity_available - (float) $productStock->quantity_reserved;

        return $netAvailable >= $requestedQuantity;
    }

    /**
     * Valida se o registro de estoque pertence à company informada.
     *
     * @param ProductStock $productStock
     * @param int $companyId
     * @return bool
     */
    private function validateCompanyAccess(ProductStock $productStock, int $companyId): bool
    {
        if ($productStock->company_id !== $companyId) {
            $this->setError(
                'Acesso negado: o registro de estoque não pertence à sua empresa',
                ['access' => ['Você não tem permissão para acessar este registro']],
                403
            );

            Log::warning('Tentativa de acesso a estoque de outra empresa', [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'product_stock_id'  => $productStock->id,
                'stock_company_id'  => $productStock->company_id,
                'user_company_id'   => $companyId,
            ]);

            return false;
        }

        return true;
    }
}
