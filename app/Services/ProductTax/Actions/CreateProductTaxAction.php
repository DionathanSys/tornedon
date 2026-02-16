<?php

namespace App\Services\ProductTax\Actions;

use App\Models\ProductTax;
use App\Services\ProductTax\Validators\ProductTaxValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class CreateProductTaxAction
{
    use HandlesActionResponse;

    public function __construct(private int $productId, private int $createdBy) {}

    public function execute(array $data): ?ProductTax
    {
        try {
            $validated = ProductTaxValidator::validateCreate($data);

            $validated['created_by'] = $this->createdBy;
            $validated['product_id'] = $this->productId;

            $productTax = ProductTax::create($validated);

            $this->setSuccess();
            return $productTax;

        } catch (QueryException $e) {
            $message = ($e->getCode() === '23000') ? 'Já existe registro de tributos para esse produto' : 'Erro ao criar imposto de produto';
            $this->setError($message);

            Log::error($this->getMessage(), [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'message'   => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'sql_code'  => $e->getCode(),
                'data'      => $data,
                'user_id'   => $this->createdBy,
                'product_id' => $this->productId,
                
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar imposto de produto');

            Log::error($this->getMessage(), [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'message'   => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
                'data'      => $data,
                'user_id'   => $this->createdBy,
                'product_id' => $this->productId,
            ]);

            return null;
        }
    }
}
