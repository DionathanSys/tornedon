<?php

namespace App\Services\ProductionOrderItem\Actions;

use App\Models\ProductionOrderItem;
use App\Services\ProductionOrderItem\Validators\ProductionOrderItemValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateProductionOrderItemAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $updatedBy,
        private ProductionOrderItem $productionOrderItem,
    ) {}

    /**
     * Atualiza um item de ordem de produção existente.
     *
     * @param array $data
     * @return ProductionOrderItem|null
     */
    public function execute(array $data): ?ProductionOrderItem
    {
        try {
            $validated = ProductionOrderItemValidator::validateUpdate($data);
            $validated['updated_by'] = $this->updatedBy;

            $this->productionOrderItem->update($validated);
            $this->productionOrderItem->refresh();

            $this->setSuccess();
            return $this->productionOrderItem;

        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'errors'                    => $e->errors(),
                'production_order_item_id'  => $this->productionOrderItem->id,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao atualizar item da ordem de produção');

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'exception'                 => $e->getMessage(),
                'sql'                       => $e->getSql(),
                'production_order_item_id'  => $this->productionOrderItem->id,
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao atualizar item');

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'exception'                 => $e->getMessage(),
                'trace'                     => $e->getTraceAsString(),
                'production_order_item_id'  => $this->productionOrderItem->id,
            ]);

            return null;
        }
    }
}
