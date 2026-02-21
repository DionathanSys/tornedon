<?php

namespace App\Services\RequisitionItem\Actions;

use App\Models\RequisitionItem;
use App\Traits\AuthorizesRequisitionItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class DeleteRequisitionItemAction
{
    use HandlesActionResponse, AuthorizesRequisitionItemActions;

    public function __construct(
        private RequisitionItem $requisitionItem,
    ) {}

    /**
     * Exclui (soft delete) um item de requisição.
     *
     * @return bool
     */
    public function execute(): bool
    {
        $this->setError('Não é permitido excluir itens desta requisição.');
        return false;

        try {
            $deleted = $this->requisitionItem->delete();

            if (! $deleted) {
                $this->setError('Não foi possível excluir o item da requisição');

                Log::error($this->getMessage(), [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'message'             => $this->getMessage(),
                    'error_code'          => $this->getErrorCode(),
                    'requisition_item_id' => $this->requisitionItem->id,
                ]);

                return false;
            }

            $this->setSuccess();
            return true;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir item da requisição');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'requisition_item_id' => $this->requisitionItem->id,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir item da requisição');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'requisition_item_id' => $this->requisitionItem->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um item de requisição.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        if (! self::canDeleteItem($this->requisitionItem->requisition_id)) {
            $this->setError('Não é permitido excluir permanentemente itens desta requisição.');
            return false;
        }

        try {
            $deleted = $this->requisitionItem->forceDelete();

            if (! $deleted) {
                $this->setError('Não foi possível excluir permanentemente o item da requisição');

                Log::error($this->getMessage(), [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'message'             => $this->getMessage(),
                    'error_code'          => $this->getErrorCode(),
                    'requisition_item_id' => $this->requisitionItem->id,
                ]);

                return false;
            }

            $this->setSuccess();
            return true;
        } catch (QueryException $e) {
            $this->setError('Erro ao excluir permanentemente item da requisição');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'requisition_item_id' => $this->requisitionItem->id,
            ]);

            return false;
        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente item da requisição');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'requisition_item_id' => $this->requisitionItem->id,
            ]);

            return false;
        }
    }
}
