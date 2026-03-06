<?php

namespace App\Services\ProductionOrder\States;

use App\Domain\Exceptions\ProductionOrder\InvalidStateTransitionException;
use App\Models\ProductionOrder;

abstract class ProductionOrderState
{
    public function __construct(
        protected ProductionOrder $productionOrder
    ) {}

    /**
     * Inicia a produção
     */
    public function start(): void
    {
        throw InvalidStateTransitionException::make(
            'iniciar produção',
            $this->name()
        );
    }

    /**
     * Envia para QC
     */
    public function sendToQC(): void
    {
        throw InvalidStateTransitionException::make(
            'enviar para QC',
            $this->name()
        );
    }

    /**
     * Retorna da QC para produção (reprovado)
     */
    public function returnToProduction(): void
    {
        throw InvalidStateTransitionException::make(
            'retornar para produção',
            $this->name()
        );
    }

    /**
     * Completa a ordem de produção
     */
    public function complete(): void
    {
        throw InvalidStateTransitionException::make(
            'concluir',
            $this->name()
        );
    }

    /**
     * Fatura a ordem de produção (completed → invoiced)
     */
    public function invoice(): void
    {
        throw InvalidStateTransitionException::make(
            'faturar',
            $this->name()
        );
    }

    /**
     * Cancela a ordem de produção
     */
    public function cancel(): void
    {
        throw InvalidStateTransitionException::make(
            'cancelar',
            $this->name()
        );
    }

    /**
     * Nome do estado
     */
    abstract public function name(): string;
}
