<?php

namespace App\Services\ServiceOrder\States;

use App\Domain\Exceptions\ServiceOrder\InvalidStateTransitionException;
use App\Models\ServiceOrder;

abstract class ServiceOrderState
{
    public function __construct(
        protected ServiceOrder $ordem
    ) {}

    public function close(): void
    {
         throw InvalidStateTransitionException::make(
            'encerrar',
            $this->name()
        );
    }

    public function invoice(): void
    {
        throw new InvalidStateTransitionException('Faturamento não permitido neste estado.');
    }

    public function cancel(): void
    {
        throw new InvalidStateTransitionException('Cancelamento não permitido neste estado.');
    }

    abstract public function name(): string;
}
