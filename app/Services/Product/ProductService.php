<?php

namespace App\Services\Product;

use App\Enum\Product\Unit;
use App\Models\Product;
use App\Services\Product\Actions\CreateProductAction;
use App\Services\Product\Actions\DeleteProductAction;
use App\Services\Product\Actions\RestoreProductAction;
use App\Services\Product\Actions\UpdateProductAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductService
{
    use HandlesServiceResponse;

    /**
     * Lista todos os produtos de uma empresa.
     *
     * @param int $companyId
     * @param array $filters
     * @return Collection
     */
    public function list(int $companyId, array $filters = []): Collection
    {
        $query = Product::where('company_id', $companyId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->with(['category', 'tax', 'stock'])->get();
    }

    /**
     * Lista produtos com paginação.
     *
     * @param int $companyId
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function paginate(int $companyId, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::where('company_id', $companyId);

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->with(['category', 'tax', 'stock'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Busca um produto pelo ID.
     *
     * @param int $id
     * @param int|null $companyId
     * @return Product|null
     */
    public function find(int $id, ?int $companyId = null): ?Product
    {
        $query = Product::where('id', $id);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        return $query->with(['category', 'tax', 'stock'])->first();
    }

    /**
     * Busca um produto pelo código.
     *
     * @param string $productCode
     * @param int $companyId
     * @return Product|null
     */
    public function findByCode(string $productCode, int $companyId): ?Product
    {
        return Product::where('product_code', $productCode)
            ->where('company_id', $companyId)
            ->with(['category', 'tax', 'stock'])
            ->first();
    }

    /**
     * Cria um novo produto.
     *
     * @param array $data
     * @param int $createdBy
     * @return Product|null
     */
    public function create(array $data, int $createdBy): ?Product
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateProductAction($createdBy);
                $product = $action->execute($data);

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

                $this->setSuccess('Produto criado com sucesso');
                return $product;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar criação do produto');

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
     * Atualiza um produto existente.
     *
     * @param Product $product
     * @param array $data
     * @param int $updatedBy
     * @return Product|null
     */
    public function update(Product $product, array $data, int $updatedBy): ?Product
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($product, $data, $updatedBy) {
                $action = new UpdateProductAction($updatedBy, $product);
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
                        'product_id'        => $product->id,
                    ]);

                    return null;
                }

                $this->setSuccess('Produto atualizado com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar atualização do produto');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $product->id,
                'data'       => $data,
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) um produto.
     *
     * @param Product $product
     * @return bool
     */
    public function delete(Product $product): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($product) {
                $action = new DeleteProductAction($product);
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
                        'product_id'        => $product->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Produto excluído com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão do produto');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $product->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um produto.
     *
     * @param Product $product
     * @return bool
     */
    public function forceDelete(Product $product): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($product) {
                $action = new DeleteProductAction($product);
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
                        'product_id'        => $product->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Produto excluído permanentemente com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão permanente do produto', ['error' => [$e->getMessage()]]);

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro ao processar exclusão permanente do produto',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $product->id,
            ]);

            return false;
        }
    }

    /**
     * Restaura um produto excluído.
     *
     * @param Product $product
     * @return bool
     */
    public function restore(Product $product): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($product) {
                $action = new RestoreProductAction($product);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error(__METHOD__ . '@' . __LINE__, [
                        'error_code'        => $this->getErrorCode(),
                        'message'           => 'Erro identificado durante execução da Action para restauração do Produto',
                        'action_message'    => $action->getMessage(),
                        'errors'            => $action->getErrors(),
                        'product_id'        => $product->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Produto restaurado com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar restauração do produto', ['error' => [$e->getMessage()]]);

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro ao processar restauração do produto',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $product->id,
            ]);

            return false;
        }
    }

    public function getUnitOfMeasure(int $productId): ?Unit
    {
        return Product::select('unit')->find($productId)->unit ?? null;
    }
}
