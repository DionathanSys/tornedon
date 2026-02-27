<?php

namespace App\Services\ProductionOrderItem;

use App\Models\ProductionOrderItem;
use App\Services\ProductionOrderItem\Actions\CreateProductionOrderItemAction;
use App\Services\ProductionOrderItem\Actions\DeleteProductionOrderItemAction;
use App\Services\ProductionOrderItem\Actions\UpdateProductionOrderItemAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionOrderItemService
{
    use HandlesServiceResponse;

    /**
     * Lista todos os itens de uma ordem de produção.
     *
     * @param int $productionOrderId
     * @return Collection
     */
    public function list(int $productionOrderId): Collection
    {
        return ProductionOrderItem::where('production_order_id', $productionOrderId)
            ->with(['product', 'createdBy', 'updatedBy'])
            ->orderBy('sequence', 'asc')
            ->get();
    }

    /**
     * Busca um item pelo ID.
     *
     * @param int $id
     * @return ProductionOrderItem|null
     */
    public function find(int $id): ?ProductionOrderItem
    {
        return ProductionOrderItem::with(['product', 'productionOrder', 'createdBy', 'updatedBy'])
            ->find($id);
    }

    /**
     * Cria um novo item de ordem de produção.
     *
     * @param array $data
     * @param int $createdBy
     * @return ProductionOrderItem|null
     */
    public function create(array $data, int $createdBy): ?ProductionOrderItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateProductionOrderItemAction($createdBy);
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
     * Atualiza um item de ordem de produção existente.
     *
     * @param ProductionOrderItem $item
     * @param array $data
     * @param int $updatedBy
     * @return ProductionOrderItem|null
     */
    public function update(ProductionOrderItem $item, array $data, int $updatedBy): ?ProductionOrderItem
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($item, $data, $updatedBy) {
                $action = new UpdateProductionOrderItemAction($updatedBy, $item);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'item_id'    => $item->id,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return null;
                }

                $this->setSuccess('Item atualizado com sucesso');
                return $updated;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar atualização do item');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'item_id'    => $item->id,
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
     * Deleta um item de ordem de produção.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $this->resetResponse();

        try {
            $item = $this->find($id);

            if (!$item) {
                $this->setError('Item não encontrado');
                return false;
            }

            return DB::transaction(function () use ($item) {
                $action = new DeleteProductionOrderItemAction();
                $result = $action->execute($item);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'item_id'    => $item->id,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return false;
                }

                $this->setSuccess('Item deletado com sucesso');
                return true;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao processar deleção do item');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'item_id'    => $id,
                'message'    => $this->getMessage(),
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
