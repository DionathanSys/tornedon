<?php

namespace App\Services\Quote\States;

use App\Enum\Quote\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Quote;
use App\Services\Quote\QuoteService;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Aprovado
 * Transições permitidas: Nenhuma (estado final de aprovação)
 * Ação possível: Converter em Ordem de Produção (não altera status)
 */
class ApprovedState implements QuoteState
{
    public function sendForApproval(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['O orçamento já foi aprovado.']]
        );
    }

    public function approve(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['O orçamento já está aprovado.']]
        );
    }

    public function reject(Quote $quote, string $reason, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível rejeitar um orçamento já aprovado.']]
        );
    }

    public function expire(Quote $quote, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível expirar um orçamento aprovado.']]
        );
    }

    public function reopen(Quote $quote, int $userId): void
    {
        Log::debug('ApprovedState: Tentativa de reabrir orçamento aprovado', [
            'metodo'   => __METHOD__ . '@' . __LINE__,
            'quote_id' => $quote->id,
            'user_id'  => $userId,
            'key'      => 'reopen_quote_action',
        ]);
        
        if(QuoteService::hasChildRecords($quote)) {
            throw new DomainValidationException(
                ['status' => ['Não é possível reabrir um orçamento aprovado que já gerou outros documentos.']]
            );
        }

        $quote->update([
            'status'     => Status::DRAFT,
            'updated_by' => $userId,
        ]);
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
