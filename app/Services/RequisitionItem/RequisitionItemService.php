<?php

namespace App\Services\RequisitionItem;

use App\Models\RequisitionItem;
use App\Services\RequisitionItem\Actions\CreateRequisitionItemAction;
use App\Services\RequisitionItem\Actions\DeleteRequisitionItemAction;
use App\Services\RequisitionItem\Actions\UpdateRequisitionItemAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RequisitionItemService
{
    use HandlesServiceResponse;

    /**
     * Lista todos os itens de uma requisição.
     *
     * @param int $requisitionId
     * @return Collection
     */
    public function list(int $requisitionId): Collection
    {
        return RequisitionItem::where('requisition_id', $requisitionId)
            ->with(['product', 'createdBy', 'updatedBy'])
            ->orderBy('created_at', 'asc')
            ->get();
    }

    /**
     * Busca um item pelo ID.
     *
     * @param int $id
     * @return RequisitionItem|null
     */
    public function find(int $id): ?RequisitionItem
    {
        return RequisitionItem::with(['product', 'requisition', 'createdBy', 'updatedBy'])
            ->find($id);
    }

    /**
     * Cria um novo item de requisição.
     *
     * @param array $data
     * @param int $createdBy
     * @return RequisitionItem|null
     */
    public function create(array $data, int $createdBy): ?RequisitionItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateRequisitionItemAction($createdBy);
                $item = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Item criado com sucesso');
                return $item;
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
     * Atualiza um item de requisição existente.
     *
     * @param RequisitionItem $requisitionItem
     * @param array $data
     * @param int $updatedBy
     * @return RequisitionItem|null
     */
    public function update(RequisitionItem $requisitionItem, array $data, int $updatedBy): ?RequisitionItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisitionItem, $data, $updatedBy) {
                $action = new UpdateRequisitionItemAction($updatedBy, $requisitionItem);
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
                        'requisition_item_id' => $requisitionItem->id,
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
                'requisition_item_id' => $requisitionItem->id,
                'data'                => $data,
            ]);

            return null;
        }
    }

    /**
     * Exclui (soft delete) um item de requisição.
     *
     * @param RequisitionItem $requisitionItem
     * @return bool
     */
    public function delete(RequisitionItem $requisitionItem): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisitionItem) {
                $action = new DeleteRequisitionItemAction($requisitionItem);
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
                        'requisition_item_id' => $requisitionItem->id,
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
                'requisition_item_id' => $requisitionItem->id,
            ]);

            return false;
        }
    }

    /**
     * Exclui permanentemente um item de requisição.
     *
     * @param RequisitionItem $requisitionItem
     * @return bool
     */
    public function forceDelete(RequisitionItem $requisitionItem): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($requisitionItem) {
                $action = new DeleteRequisitionItemAction($requisitionItem);
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
                        'requisition_item_id' => $requisitionItem->id,
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
                'requisition_item_id' => $requisitionItem->id,
            ]);

            return false;
        }
    }
}
