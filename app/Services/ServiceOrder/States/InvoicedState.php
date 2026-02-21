<?php

namespace App\Services\ServiceOrder\States;

use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;

/**
 * Estado: Faturada
 * Estado terminal — sem transições permitidas.
 */
class InvoicedState implements ServiceOrderState
{
    public function close(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível encerrar uma ordem faturada.']]
        );
    }

    public function invoice(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['A ordem já está faturada.']]
        );
    }

    public function cancel(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível cancelar uma ordem faturada.']]
        );
    }

    public function reopen(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível reabrir uma ordem faturada.']]
        );
    }

    public function canTransitionTo(string $transition): bool
    {
        return false;
    }
}
