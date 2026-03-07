<?php

namespace App\Services\Requisition\States;

use App\Exceptions\DomainValidationException;
use App\Models\Requisition;

interface RequisitionState
{
    /**
     * Encerra a requisição (open → closed).
     *
     * @throws DomainValidationException
     */
    public function close(Requisition $requisition, int $userId): void;

    /**
     * Fatura a requisição (closed → invoiced).
     *
     * @throws DomainValidationException
     */
    public function invoice(Requisition $requisition, int $userId, int $invoiceId): void;

    /**
     * Cancela a requisição.
     *
     * @throws DomainValidationException
     */
    public function cancel(Requisition $requisition, int $userId): void;

    /**
     * Reabre a requisição (closed|cancelled → open).
     *
     * @throws DomainValidationException
     */
    public function reopen(Requisition $requisition, int $userId): void;

    /**
     * Retorna true se a transição nomeada é permitida a partir deste estado.
     */
    public function canTransitionTo(string $transition): bool;

    /**
     * Retorna true se a requisição pode ser editada neste estado.
     */
    public function canEdit(): bool;
}
