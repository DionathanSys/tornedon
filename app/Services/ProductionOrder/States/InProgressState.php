<?php

namespace App\Services\ProductionOrder\States;

use App\Services\ProductionOrder\Actions\CancelProductionAction;
use App\Services\ProductionOrder\Actions\SendToQcAction;

class InProgressState extends ProductionOrderState
{
    public function sendToQC(): void
    {
        $action = new SendToQcAction($this->productionOrder->updated_by ?? $this->productionOrder->created_by);
        $result = $action->execute($this->productionOrder);

        if (! $result) {
            throw new \RuntimeException($action->getMessage() ?? 'Erro ao enviar para QC');
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
        return 'Em Produção';
    }
}
