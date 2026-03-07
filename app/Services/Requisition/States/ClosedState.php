<?php

namespace App\Services\Requisition\States;

use App\Enum\Requisition\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Encerrada
 * Transições permitidas: Faturar, Reabrir
 */
class ClosedState implements RequisitionState
{
    public function close(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['A requisição já está encerrada.']]
        );
    }

    public function invoice(Requisition $requisition, int $userId, int $invoiceId): void
    {
        Log::info('Requisition: Faturando requisição (closed → invoiced)', [
            'requisition_id' => $requisition->id,
            'user_id'        => $userId,
            'invoice_id'     => $invoiceId,
        ]);

        $requisition->update([
            'status'      => Status::INVOICED,
            'invoice_id'  => $invoiceId,
            'invoiced_at' => now(),
            'updated_by'  => $userId,
        ]);
    }

    public function cancel(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível cancelar uma requisição encerrada. Reabra-a antes de cancelar.']]
        );
    }

    public function reopen(Requisition $requisition, int $userId): void
    {
        Log::info('Requisition: Reabrindo requisição (closed → open)', [
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
        return in_array($transition, ['invoice', 'reopen']);
    }
}
