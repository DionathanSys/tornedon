<?php

namespace App\Services\ProductionOrder\States;

class CancelledState extends ProductionOrderState
{
    public function name(): string
    {
        return 'Cancelado';
    }

    // Estado final - nenhuma transição permitida
    // Todas as ações lançarão InvalidStateTransitionException
}
