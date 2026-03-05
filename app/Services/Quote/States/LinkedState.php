<?php

namespace App\Services\Quote\States;

use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Vinculado
 * O orçamento foi aprovado e seus itens já geraram documentos (requisição, OS, OP).
 * Nenhuma transição de estado é permitida e o formulário é bloqueado para edição.
 */
class LinkedState implements QuoteState
{
    public function sendForApproval(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['O orçamento já está vinculado a outros documentos.']]
        );
    }

    public function approve(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['O orçamento já está vinculado a outros documentos.']]
        );
    }

    public function reject(Quote $quote, string $reason, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível rejeitar um orçamento vinculado.']]
        );
    }

    public function expire(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível expirar um orçamento vinculado.']]
        );
    }

    public function reopen(Quote $quote, int $userId): void
    {
        Log::debug('LinkedState: Tentativa de reabrir orçamento vinculado', [
            'metodo'   => __METHOD__ . '@' . __LINE__,
            'quote_id' => $quote->id,
            'user_id'  => $userId,
        ]);

        throw new DomainValidationException(
            ['status' => ['Não é possível reabrir um orçamento vinculado a outros documentos.']]
        );
    }

    public function canTransitionTo(string $transition): bool
    {
        return false;
    }

    public function canEdit(): bool
    {
        return false;
    }
}
