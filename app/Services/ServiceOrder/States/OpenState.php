<?php

namespace App\Services\ServiceOrder\States;

use App\Enum\ServiceOrder\State;
use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Aberta
 * Transições permitidas: Encerrar, Cancelar
 */
class OpenState implements ServiceOrderState
{
    public function close(ServiceOrder $order, int $userId): void
    {
        Log::info('ServiceOrder: Encerrando ordem de serviço (aberta → encerrada)', [
            'service_order_id' => $order->id,
            'user_id'          => $userId,
        ]);

        $order->update([
            'status'          => State::CLOSED,
            'completion_date' => $order->completion_date ?? now()->toDateString(),
            'updated_by'      => $userId,
        ]);
    }

    public function invoice(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível faturar uma ordem aberta. Encerre-a antes de faturar.']]
        );
    }

    public function cancel(ServiceOrder $order, int $userId): void
    {
        Log::info('ServiceOrder: Cancelando ordem de serviço (aberta → cancelada)', [
            'service_order_id' => $order->id,
            'user_id'          => $userId,
        ]);

        $order->update([
            'status'     => State::CANCELLED,
            'updated_by' => $userId,
        ]);
    }

    public function reopen(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['A ordem já está aberta.']]
        );
    }

    public function canTransitionTo(string $transition): bool
    {
        return in_array($transition, ['close', 'cancel']);
    }
}
