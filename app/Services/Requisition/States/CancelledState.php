<?php

namespace App\Services\Requisition\States;

use App\Enum\Requisition\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Cancelada
 * Transições permitidas: Reabrir
 */
class CancelledState implements RequisitionState
{
    public function close(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível encerrar uma requisição cancelada. Reabra-a primeiro.']]
        );
    }

    public function invoice(Requisition $requisition, int $userId, int $invoiceId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível faturar uma requisição cancelada.']]
        );
    }

    public function cancel(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['A requisição já está cancelada.']]
        );
    }

    public function reopen(Requisition $requisition, int $userId): void
    {
        Log::info('Requisition: Reabrindo requisição (cancelled → open)', [
            'requisition_id' => $requisition->id,
            'user_id'        => $userId,
        ]);

        $requisition->update([
            'status'     => Status::OPEN,
            'updated_by' => $userId,
        ]);
    }

    public function canTransitionTo(string $transition): bool
    {
        return $transition === 'reopen';
    }

    public function canEdit(): bool
    {
        return false;
    }
}
