<?php

namespace App\Services\ProductionOrder\States;

use App\Services\ProductionOrder\Actions\CancelProductionAction;
use App\Services\ProductionOrder\Actions\StartProduction;

class QueuedState extends ProductionOrderState
{
    public function start(): void
    {
        $action = new StartProduction($this->productionOrder->updated_by ?? $this->productionOrder->created_by);
        $result = $action->execute($this->productionOrder);

        if (! $result) {
            throw new \RuntimeException($action->getMessage() ?? 'Erro ao iniciar produção');
        }
    }

    public function cancel(): void
    {
        $action = new CancelProductionAction($this->productionOrder->updated_by ?? $this->productionOrder->created_by);
        $result = $action->execute($this->productionOrder);

        if (! $result) {
            throw new \RuntimeException($action->getMessage() ?? 'Erro ao cancelar ordem de produção');
        }
    }

    public function name(): string
    {
        return 'Na Fila';
    }
}
