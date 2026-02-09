<?php

namespace App\Services\ProductionOrder\States;

class CompletedState extends ProductionOrderState
{
    public function name(): string
    {
        return 'Concluído';
    }

    // Estado final - nenhuma transição permitida
    // Todas as ações lançarão InvalidStateTransitionException
}
