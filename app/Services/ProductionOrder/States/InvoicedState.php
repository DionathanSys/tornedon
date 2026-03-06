<?php

namespace App\Services\ProductionOrder\States;

/**
 * Estado: Faturada — estado terminal após emissão de NF-e.
 */
class InvoicedState extends ProductionOrderState
{
    public function name(): string
    {
        return 'Faturada';
    }

    // Estado terminal — nenhuma transição permitida.
    // Todas as ações lançarão InvalidStateTransitionException via herança.
}
