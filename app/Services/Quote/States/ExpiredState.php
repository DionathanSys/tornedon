<?php

namespace App\Services\Quote\States;

use App\Enum\Quote\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Expirado
 * Transições permitidas: Reabrir (volta para rascunho)
 */
class ExpiredState implements QuoteState
{
    public function sendForApproval(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível enviar para aprovação um orçamento expirado. Reabra-o primeiro.']]
        );
    }

    public function approve(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível aprovar um orçamento expirado.']]
        );
    }

    public function reject(Quote $quote, string $reason, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível rejeitar um orçamento expirado.']]
        );
    }

    public function expire(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['O orçamento já está expirado.']]
        );
    }

    public function reopen(Quote $quote, int $userId): void
    {
        Log::info('Quote: Reabrindo orçamento expirado (expired → draft)', [
            'quote_id' => $quote->id,
            'user_id'  => $userId,
        ]);

        $quote->update([
            'status'     => Status::DRAFT,
            'updated_by' => $userId,
        ]);
    }

    public function canTransitionTo(string $transition): bool
    {
        return $transition === 'reopen';
    }
}
