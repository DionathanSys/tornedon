<?php

namespace App\Services\ProductionOrderItem\Actions;

use App\Models\ProductionOrderItem;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteProductionOrderItemAction
{
    use HandlesActionResponse;

    /**
     * Deleta um item de ordem de produção.
     *
     * @param ProductionOrderItem $item
     * @return bool
     */
    public function execute(ProductionOrderItem $item): bool
    {
        try {
            $item->delete();
            $this->setSuccess();
            return true;

        } catch (QueryException $e) {
            $this->setError('Erro ao deletar item da ordem de produção');

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'exception'                 => $e->getMessage(),
                'sql'                       => $e->getSql(),
                'production_order_item_id'  => $item->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao deletar item');

            Log::error($this->getMessage(), [
                'metodo'                    => __METHOD__ . '@' . __LINE__,
                'message'                   => $this->getMessage(),
                'error_code'                => $this->getErrorCode(),
                'exception'                 => $e->getMessage(),
                'trace'                     => $e->getTraceAsString(),
                'production_order_item_id'  => $item->id,
            ]);

            return false;
        }
    }
}
