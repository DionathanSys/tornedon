<?php

namespace App\Services\ServiceOrder\States;

use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;

interface ServiceOrderState
{
    /**
     * Encerra a ordem de serviço.
     *
     * @throws DomainValidationException
     */
    public function close(ServiceOrder $order, int $userId): void;

    /**
     * Marca a ordem de serviço como faturada.
     *
     * @throws DomainValidationException
     */
    public function invoice(ServiceOrder $order, int $userId): void;

    /**
     * Cancela a ordem de serviço.
     *
     * @throws DomainValidationException
     */
    public function cancel(ServiceOrder $order, int $userId): void;

    /**
     * Reabre uma ordem encerrada ou cancelada.
     *
     * @throws DomainValidationException
     */
    public function reopen(ServiceOrder $order, int $userId): void;

    /**
     * Retorna true se a transição que recebe o $transition é possível.
     */
    public function canTransitionTo(string $transition): bool;

    /**
     * Retorna true se a ordem de serviço pode ser editada neste estado.
     */
    public function canEdit(): bool;
}
