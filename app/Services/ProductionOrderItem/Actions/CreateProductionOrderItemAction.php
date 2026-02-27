<?php

namespace App\Services\ProductionOrderItem\Actions;

use App\Models\ProductionOrderItem;
use App\Services\ProductionOrderItem\Validators\ProductionOrderItemValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateProductionOrderItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    /**
     * Cria um novo item de ordem de produção.
     *
     * @param array $data
     * @return ProductionOrderItem|null
     */
    public function execute(array $data): ?ProductionOrderItem
    {
        try {
            $validated = ProductionOrderItemValidator::validateCreate($data);
            $validated['created_by'] = $this->createdBy;

            $item = ProductionOrderItem::create($validated);

            $this->setSuccess();
            return $item;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'errors'     => $e->errors(),
                'data'       => $data,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao criar item da ordem de produção');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'sql'        => $e->getSql(),
                'data'       => $data,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao criar item');

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
