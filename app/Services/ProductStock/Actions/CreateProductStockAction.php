<?php

namespace App\Services\ProductStock\Actions;

use App\Models\ProductStock;
use App\Services\ProductStock\Validators\ProductStockValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateProductStockAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo registro de estoque de produto.
     *
     * @param array $data
     * @return ProductStock|null
     */
    public function execute(array $data): ?ProductStock
    {
        try {
            $validated = ProductStockValidator::validateCreate($data);

            $validated['created_by'] = $this->createdBy;

            $productStock = ProductStock::create($validated);

            $this->setSuccess();
            return $productStock;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;

        } catch (QueryException $e) {
            $message = ($e->getCode() === '23000')
                ? 'Já existe um registro de estoque para este produto'
                : 'Erro ao criar estoque no banco de dados';

            $this->setError($message);

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql_code'   => $e->getCode(),
                'data'       => $data,
                'user_id'    => $this->createdBy,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar estoque');

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
}
