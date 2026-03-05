<?php

namespace App\Services\Quote\States;

use App\Exceptions\DomainValidationException;
use App\Models\Quote;

interface QuoteState
{
    /**
     * Envia o orçamento para aprovação (draft → sent).
     *
     * @throws DomainValidationException
     */
    public function sendForApproval(Quote $quote, int $userId): void;

    /**
     * Aprova o orçamento (sent → approved).
     *
     * @throws DomainValidationException
     */
    public function approve(Quote $quote, int $userId): void;

    /**
     * Rejeita o orçamento.
     *
     * @throws DomainValidationException
     */
    public function reject(Quote $quote, string $reason, int $userId): void;

    /**
     * Expira o orçamento (sent → expired).
     *
     * @throws DomainValidationException
     */
    public function expire(Quote $quote, int $userId): void;

    /**
     * Reabre o orçamento (rejected|expired → draft).
     *
     * @throws DomainValidationException
     */
    public function reopen(Quote $quote, int $userId): void;

    /**
     * Retorna true se a transição nomeada é permitida a partir deste estado.
     */
    public function canTransitionTo(string $transition): bool;

    /**
     * Retorna true se o orçamento pode ser editado neste estado.
     */
    public function canEdit(): bool;
}
