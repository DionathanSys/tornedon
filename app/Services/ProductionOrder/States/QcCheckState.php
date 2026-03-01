<?php

namespace App\Services\ProductionOrder\States;

use App\Services\ProductionOrder\Actions\CancelProductionAction;
use App\Services\ProductionOrder\Actions\CompleteProduction;
use App\Services\ProductionOrder\Actions\ReturnToProductionAction;

class QcCheckState extends ProductionOrderState
{
    public function complete(): void
    {
        $action = new CompleteProduction($this->productionOrder->updated_by ?? $this->productionOrder->created_by);
        $result = $action->execute($this->productionOrder);

        if (! $result) {
            throw new \RuntimeException($action->getMessage() ?? 'Erro ao concluir ordem de produção');
        }
    }

    public function returnToProduction(): void
    {
        $action = new ReturnToProductionAction($this->productionOrder->updated_by ?? $this->productionOrder->created_by);
        $result = $action->execute($this->productionOrder);

        if (! $result) {
            throw new \RuntimeException($action->getMessage() ?? 'Erro ao retornar para produção');
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
        return 'Controle de Qualidade';
    }
}
