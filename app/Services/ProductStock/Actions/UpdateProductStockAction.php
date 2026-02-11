<?php

namespace App\Services\ProductStock\Actions;

use App\Models\ProductStock;
use App\Services\ProductStock\Validators\ProductStockValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateProductStockAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
        private ProductStock $productStock,
    ) {}

    /**
     * Atualiza um registro de estoque existente.
     *
     * @param array $data
     * @return ProductStock|null
     */
    public function execute(array $data): ?ProductStock
    {
        try {
            $validated = ProductStockValidator::validateUpdate($data, $this->productStock->id);

            unset($validated['company_id']);

            $validated['updated_by'] = $this->updatedBy;

            $this->productStock->update($validated);

            $this->setSuccess();
            return $this->productStock;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'error_code'        => $this->getErrorCode(),
                'message'           => $this->getMessage(),
                'errors'            => $e->errors(),
                'product_stock_id'  => $this->productStock->id,
                'data'              => $data,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar estoque no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'error_code'        => $this->getErrorCode(),
                'message'           => $this->getMessage(),
                'exception'         => $e->getMessage(),
                'sql_code'          => $e->getCode(),
                'product_stock_id'  => $this->productStock->id,
                'data'              => $data,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar estoque');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'error_code'        => $this->getErrorCode(),
                'message'           => $this->getMessage(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_stock_id'  => $this->productStock->id,
                'data'              => $data,
            ]);

            return null;
        }
    }
}
