<?php

namespace App\Services\Requisition\States;

use App\Exceptions\DomainValidationException;
use App\Models\Requisition;

/**
 * Estado: Faturada
 * Estado terminal — sem transições permitidas.
 */
class InvoicedState implements RequisitionState
{
    public function close(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível encerrar uma requisição faturada.']]
        );
    }

    public function invoice(Requisition $requisition, int $userId, int $invoiceId): void
    {
        throw new DomainValidationException(
            ['status' => ['A requisição já está faturada.']]
        );
    }

    public function cancel(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível cancelar uma requisição faturada.']]
        );
    }

    public function reopen(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível reabrir uma requisição faturada.']]
        );
    }

    public function canTransitionTo(string $transition): bool
    {
        return false;
    }
}
