<?php

namespace App\Services\ProductionOrder;

use App\Models\ProductionOrder;
use App\Services\ProductionOrder\Actions\CompleteProduction;
use App\Services\ProductionOrder\Actions\CreateProductionOrder;
use App\Services\ProductionOrder\Actions\StartProduction;
use App\Services\ProductionOrder\Actions\UpdateProgress;
use App\Traits\HandlesServiceResponse;

class ProductionOrderService
{
    use HandlesServiceResponse;

    public function create(array $data, int $createdBy): ?ProductionOrder
    {
        $action = new CreateProductionOrder($createdBy);
        $productionOrder = $action->execute($data);

        if ($action->isSuccess()) {
            $this->setSuccess('Ordem de produção criada com sucesso');
            return $productionOrder;
        }

        $this->setError($action->getMessage(), $action->getErrors());
        return null;
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
}
