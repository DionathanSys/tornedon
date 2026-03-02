<?php

namespace App\Services\ProductionOrder;

use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Services\ProductionOrder\Actions\CancelProductionAction;
use App\Services\ProductionOrder\Actions\CompleteProduction;
use App\Services\ProductionOrder\Actions\CreateProductionOrder;
use App\Services\ProductionOrder\Actions\GenerateRequisitionFromProductionAction;
use App\Services\ProductionOrder\Actions\ReturnToProductionAction;
use App\Services\ProductionOrder\Actions\SendToQcAction;
use App\Services\ProductionOrder\Actions\StartProduction;
use App\Services\ProductionOrder\Actions\UpdateProgress;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductionOrderService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?ProductionOrder
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                $action = new CreateProductionOrder($createdBy);
                $productionOrder = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('ProductionOrderService: ' . $this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Ordem de produção criada com sucesso');

                Log::info('ProductionOrderService: Ordem de produção criada com sucesso', [
                    'metodo'       => __METHOD__ . '@' . __LINE__,
                    'production_order_id'     => $productionOrder->id,
                    'production_order_number' => $productionOrder->production_order_number,
                ]);

                return $productionOrder;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar ordem de produção');

            Log::error('ProductionOrderService: ' . $this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    public function start(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new StartProduction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Produção iniciada');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function updateProgress(ProductionOrder $productionOrder, array $itemsProgress, int $userId): bool
    {
        $action = new UpdateProgress($userId);
        $result = $action->execute($productionOrder, $itemsProgress);

        if ($action->isSuccess()) {
            $this->setSuccess('Progresso atualizado com sucesso');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function complete(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new CompleteProduction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Produção concluída com sucesso');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function sendToQc(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new SendToQcAction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem enviada para controle de qualidade');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function returnToProduction(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new ReturnToProductionAction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem retornada para produção');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    public function cancel(ProductionOrder $productionOrder, int $userId): bool
    {
        $action = new CancelProductionAction($userId);
        $result = $action->execute($productionOrder);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem de produção cancelada');
            return true;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return false;
    }

    /**
     * Gera uma requisição a partir de uma ordem de produção concluída.
     * Os itens aprovados da PO são convertidos em itens da requisição.
     * A requisição fica vinculada à PO (bidirecional).
     */
    public function generateRequisition(ProductionOrder $productionOrder, int $userId): ?Requisition
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($productionOrder, $userId) {
                $action = new GenerateRequisitionFromProductionAction($userId);
                $requisition = $action->execute($productionOrder);

                if ($action->hasError() || $requisition === null) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error('ProductionOrderService: ' . $this->getMessage(), [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'error_code'          => $this->getErrorCode(),
                        'errors'              => $action->getErrors(),
                        'production_order_id' => $productionOrder->id,
                        'user_id'             => $userId,
                    ]);

                    return null;
                }

                $this->setSuccess('Requisição gerada com sucesso a partir da ordem de produção');

                Log::info('ProductionOrderService: Requisição gerada com sucesso', [
                    'metodo'              => __METHOD__ . '@' . __LINE__,
                    'production_order_id' => $productionOrder->id,
                    'requisition_id'      => $requisition->id,
                ]);

                return $requisition;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar requisição a partir da ordem de produção');

            Log::error('ProductionOrderService: ' . $this->getMessage(), [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'error_code'          => $this->getErrorCode(),
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'production_order_id' => $productionOrder->id,
                'user_id'             => $userId,
            ]);

            return null;
        }
    }
}
