<?php

namespace App\Services\Quote\States;

use App\Enum\Quote\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Rascunho
 * Transições permitidas: Enviar para Aprovação
 */
class DraftState implements QuoteState
{
    public function sendForApproval(Quote $quote, int $userId): void
    {
        if ($quote->items()->count() === 0) {
            throw new DomainValidationException(
                ['items' => ['O orçamento deve ter ao menos um item antes de ser enviado para aprovação.']]
            );
        }

        Log::info('Quote: Enviando orçamento para aprovação (draft → sent)', [
            'quote_id' => $quote->id,
            'user_id'  => $userId,
        ]);

        $quote->update([
            'status'     => Status::SENT,
            'updated_by' => $userId,
        ]);
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

        Log::info('Quote: Aprovando orçamento (draft → approved)', [
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

        Log::info('Quote: Rejeitando orçamento (draft → rejected)', [
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
        throw new DomainValidationException(
            ['status' => ['Apenas orçamentos enviados podem ser expirados.']]
        );
    }

    public function reopen(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['O orçamento já está em rascunho.']]
        );
    }

    public function canTransitionTo(string $transition): bool
    {
        return $transition === 'sendForApproval';
    }

    public function canEdit(): bool
    {
        return true;
    }
}
