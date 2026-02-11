<?php

namespace App\Services\Product\Actions;

use App\Models\Product;
use App\Services\Product\Validators\ProductValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateProductAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
        private Product $product,
    ) {}

    /**
     * Atualiza um produto existente.
     *
     * @param array $data
     * @return Product|null
     */
    public function execute(array $data): ?Product
    {
        try {
            // Validação
            $validated = ProductValidator::validateUpdate($data, $this->product->id, $this->product->company_id);

            // Remove campos que não devem ser atualizados
            unset($validated['product_code'], $validated['company_id']);

            $validated['updated_by'] = $this->updatedBy;

            // Persistência
            $this->product->update($validated);

            $this->setSuccess();
            return $this->product;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $this->getMessage(),
                'errors'     => $e->errors(),
                'product_id' => $this->product->id,
                'data'       => $data,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar produto no banco de dados');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $this->getMessage(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'product_id' => $this->product->id,
                'data'       => $data,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar produto');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $this->getMessage(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'product_id' => $this->product->id,
                'data'       => $data,
            ]);

            return null;
        }
    }
}
