<?php

namespace App\Services\ServiceOrderItem\Actions;

use App\Enum\ServiceOrder\State;
use App\Models\ServiceOrderItem;
use App\Traits\AuthorizesServiceOrderItemActions;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class DeleteServiceOrderItemAction
{
    use HandlesActionResponse, AuthorizesServiceOrderItemActions;

    public function __construct(
        private ServiceOrderItem $serviceOrderItem,
    ) {}

    /**
     * Exclui (soft delete) um item de ordem de serviço.
     *
     * @return bool
     */
    public function execute(): bool
    {
        if (! self::canDeleteItem($this->serviceOrderItem->service_order_id)) {
            $this->setError('Não é permitido excluir itens desta ordem de serviço.');
            return false;
        }
        
        try {
            $deleted = $this->serviceOrderItem->delete();

            if (!$deleted) {
                $this->setError('Não foi possível excluir o item da ordem de serviço');

                Log::error($this->getMessage(), [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'message'             => $this->getMessage(),
                    'error_code'          => $this->getErrorCode(),
                    'service_order_item_id' => $this->serviceOrderItem->id,
                ]);

                return false;
            }

            $this->setSuccess();
            return true;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'service_order_item_id' => $this->serviceOrderItem->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'service_order_item_id' => $this->serviceOrderItem->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um item de ordem de serviço.
     *
     * @return bool
     */
    public function forceDelete(): bool
    {
        $serviceOrder = $this->serviceOrderItem->serviceOrder;

        if (! self::canDeleteItem($this->serviceOrderItem->service_order_id)) {
            $this->setError('Não é permitido excluir permanentemente itens desta ordem de serviço.');
            return false;
        }

        try {
            $deleted = $this->serviceOrderItem->forceDelete();

            if (!$deleted) {
                $this->setError('Não foi possível excluir permanentemente o item da ordem de serviço');

                Log::error($this->getMessage(), [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'message'             => $this->getMessage(),
                    'error_code'          => $this->getErrorCode(),
                    'service_order_item_id' => $this->serviceOrderItem->id,
                ]);

                return false;
            }

            $this->setSuccess();
            return true;

        } catch (QueryException $e) {
            $this->setError('Erro ao excluir permanentemente item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'service_order_item_id' => $this->serviceOrderItem->id,
            ]);

            return false;

        } catch (\Exception $e) {
            $this->setError('Erro inesperado ao excluir permanentemente item da ordem de serviço');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'service_order_item_id' => $this->serviceOrderItem->id,
            ]);

            return false;
        }
    }
}
