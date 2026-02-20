<?php

namespace App\Services\ServiceOrderItem;

use App\Models\ServiceOrderItem;
use App\Services\ServiceOrderItem\Actions\CreateServiceOrderItemAction;
use App\Services\ServiceOrderItem\Actions\DeleteServiceOrderItemAction;
use App\Services\ServiceOrderItem\Actions\UpdateServiceOrderItemAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ServiceOrderItemService
{
    use HandlesServiceResponse;

    /**
     * Lista todos os itens de uma ordem de serviço.
     *
     * @param int $serviceOrderId
     * @return Collection
     */
    public function list(int $serviceOrderId): Collection
    {
        return ServiceOrderItem::where('service_order_id', $serviceOrderId)
            ->with(['service', 'createdBy', 'updatedBy'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Busca um item pelo ID.
     *
     * @param int $id
     * @return ServiceOrderItem|null
     */
    public function find(int $id): ?ServiceOrderItem
    {
        return ServiceOrderItem::with(['service', 'serviceOrder', 'createdBy', 'updatedBy'])
            ->find($id);
    }

    /**
     * Cria um novo item de ordem de serviço.
     *
     * @param array $data
     * @param int $createdBy
     * @return ServiceOrderItem|null
     */
    public function create(array $data, int $createdBy): ?ServiceOrderItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateServiceOrderItemAction($createdBy);
                $serviceOrderItem = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'            => __METHOD__ . '@' . __LINE__,
                        'message'           => $this->getMessage(),
                        'error_code'        => $this->getErrorCode(),
                        'action_message'    => $action->getMessage(),
                        'errors'            => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Item criado com sucesso');
                return $serviceOrderItem;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar criação do item');

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

    /**
     * Atualiza um item de ordem de serviço existente.
     *
     * @param ServiceOrderItem $serviceOrderItem
     * @param array $data
     * @param int $updatedBy
     * @return ServiceOrderItem|null
     */
    public function update(ServiceOrderItem $serviceOrderItem, array $data, int $updatedBy): ?ServiceOrderItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrderItem, $data, $updatedBy) {
                $action = new UpdateServiceOrderItemAction($updatedBy, $serviceOrderItem);
                $result = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'message'             => $this->getMessage(),
                        'error_code'          => $this->getErrorCode(),
                        'errors'              => $action->getErrors(),
                        'service_order_item_id' => $serviceOrderItem->id,
                    ]);

                    return null;
                }

                $this->setSuccess('Item atualizado com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar atualização do item');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'service_order_item_id' => $serviceOrderItem->id,
                'data'                => $data,
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) um item de ordem de serviço.
     *
     * @param ServiceOrderItem $serviceOrderItem
     * @return bool
     */
    public function delete(ServiceOrderItem $serviceOrderItem): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrderItem) {
                $action = new DeleteServiceOrderItemAction($serviceOrderItem);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'message'             => $this->getMessage(),
                        'error_code'          => $this->getErrorCode(),
                        'action_message'      => $action->getMessage(),
                        'errors'              => $action->getErrors(),
                        'service_order_item_id' => $serviceOrderItem->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Item excluído com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão do item');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'service_order_item_id' => $serviceOrderItem->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um item de ordem de serviço.
     *
     * @param ServiceOrderItem $serviceOrderItem
     * @return bool
     */
    public function forceDelete(ServiceOrderItem $serviceOrderItem): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrderItem) {
                $action = new DeleteServiceOrderItemAction($serviceOrderItem);
                $result = $action->forceDelete();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'message'             => $this->getMessage(),
                        'error_code'          => $this->getErrorCode(),
                        'action_message'      => $action->getMessage(),
                        'errors'              => $action->getErrors(),
                        'service_order_item_id' => $serviceOrderItem->id,
                    ]);

                    return false;
                }

                $this->setSuccess('Item excluído permanentemente com sucesso');
                return $result;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar exclusão permanente do item');

            Log::error($this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'message'             => $this->getMessage(),
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'service_order_item_id' => $serviceOrderItem->id,
            ]);

            return false;
        }
    }
}
