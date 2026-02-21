<?php

namespace App\Services\Requisition\States;

use App\Enum\Requisition\Status;
use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Aberta
 * Transições permitidas: Encerrar, Cancelar
 */
class OpenState implements RequisitionState
{
    public function close(Requisition $requisition, int $userId): void
    {
        Log::info('Requisition: Encerrando requisição (open → closed)', [
            'requisition_id' => $requisition->id,
            'user_id'        => $userId,
        ]);

        $requisition->update([
            'status'     => Status::CLOSED,
            'updated_by' => $userId,
        ]);
    }

    public function invoice(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível faturar uma requisição aberta. Encerre-a antes de faturar.']]
        );
    }

    public function cancel(Requisition $requisition, int $userId): void
    {
        Log::info('Requisition: Cancelando requisição (open → cancelled)', [
            'requisition_id' => $requisition->id,
            'user_id'        => $userId,
        ]);

        $requisition->update([
            'status'     => Status::CANCELLED,
            'updated_by' => $userId,
        ]);
    }

    public function reopen(Requisition $requisition, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['A requisição já está aberta.']]
        );
    }

    public function canTransitionTo(string $transition): bool
    {
        return in_array($transition, ['close', 'cancel']);
    }
}
