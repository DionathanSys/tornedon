<?php

namespace App\Services\Quote\States;

use App\Enum\Quote\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Enviado (Aguardando Aprovação)
 * Transições permitidas: Aprovar, Rejeitar, Expirar
 */
class SentState implements QuoteState
{
    public function sendForApproval(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['O orçamento já foi enviado para aprovação.']]
        );
    }

    public function approve(Quote $quote, int $userId): void
    {
        if ($quote->isExpired()) {
            $quote->update([
                'status'     => Status::EXPIRED,
            ]);
            
            throw new DomainValidationException(
                ['valid_until' => ['Não é possível aprovar um orçamento com prazo de validade expirado.']]
            );
        }

        Log::info('Quote: Aprovando orçamento (sent → approved)', [
            'quote_id' => $quote->id,
            'user_id'  => $userId,
        ]);

        $quote->update([
            'status'      => Status::APPROVED,
            'approved_at' => now(),
            'approved_by' => $userId,
            'updated_by'  => $userId,
        ]);

        $quote->refresh();
    }

    public function reject(Quote $quote, string $reason, int $userId): void
    {
        if (empty(trim($reason))) {
            throw new DomainValidationException(
                ['rejected_reason' => ['O motivo da rejeição é obrigatório.']]
            );
        }

        Log::info('Quote: Rejeitando orçamento (sent → rejected)', [
            'quote_id' => $quote->id,
            'user_id'  => $userId,
        ]);

        $quote->update([
            'status'          => Status::REJECTED,
            'rejected_reason' => $reason,
            'updated_by'      => $userId,
        ]);
    }

    public function expire(Quote $quote, int $userId): void
    {
        Log::info('Quote: Expirando orçamento (sent → expired)', [
            'quote_id' => $quote->id,
            'user_id'  => $userId,
        ]);

        $quote->update([
            'status'     => Status::EXPIRED,
            'updated_by' => $userId,
        ]);
    }

    public function reopen(Quote $quote, int $userId): void
    {
        Log::info('Quote: Reabrindo orçamento enviado (sent → draft)', [
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
        return in_array($transition, ['approve', 'reject', 'expire']);
    }

    public function canEdit(): bool
    {
        return false;
    }
}
