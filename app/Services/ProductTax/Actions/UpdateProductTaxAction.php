<?php

namespace App\Services\ProductTax\Actions;

use App\Models\ProductTax;
use App\Services\ProductTax\Validators\ProductTaxValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateProductTaxAction
{
    use HandlesActionResponse;

    public function __construct(
        private ProductTax $productTax,
        private int $updatedBy,
    ) {}

    /**
     * Atualiza os dados de tributos de um produto.
     *
     * @param array $data
     * @return ProductTax|null
     */
    public function execute(array $data): ?ProductTax
    {
        try {
            $data['product_id'] = $this->productTax->product_id;

            $validated = ProductTaxValidator::validateUpdate($data, $this->productTax->id);

            unset($validated['product_id']);

            $validated['updated_by'] = $this->updatedBy;

            $this->productTax->update($validated);

            $this->setSuccess();
            return $this->productTax;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados de tributos', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'errors'            => $e->errors(),
                'product_tax_id'    => $this->productTax->id,
                'data'              => $data,
                'user_id'           => $this->updatedBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $message = ($e->getCode() === '23000')
                ? 'Já existe registro de tributos para esse produto'
                : 'Erro ao atualizar imposto de produto';

            $this->setError($message);

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'sql_code'          => $e->getCode(),
                'product_tax_id'    => $this->productTax->id,
                'data'              => $data,
                'user_id'           => $this->updatedBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar imposto de produto');

            Log::error($this->getMessage(), [
                'metodo'            => __METHOD__ . '@' . __LINE__,
                'message'           => $this->getMessage(),
                'error_code'        => $this->getErrorCode(),
                'exception'         => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
                'product_tax_id'    => $this->productTax->id,
                'data'              => $data,
                'user_id'           => $this->updatedBy,
            ]);

            return null;
        }
    }
}
