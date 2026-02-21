<?php

namespace App\Services\ServiceOrder\States;

use App\Enum\ServiceOrder\State;
use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Cancelada
 * Transições permitidas: Reabrir
 */
class CancelledState implements ServiceOrderState
{
    public function close(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível encerrar uma ordem cancelada. Reabra-a primeiro.']]
        );
    }

    public function invoice(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível faturar uma ordem cancelada.']]
        );
    }

    public function cancel(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['A ordem já está cancelada.']]
        );
    }

    public function reopen(ServiceOrder $order, int $userId): void
    {
        Log::info('ServiceOrder: Reabrindo ordem de serviço (cancelada → aberta)', [
            'service_order_id' => $order->id,
            'user_id'          => $userId,
        ]);

        $order->update([
            'status'     => State::OPEN,
            'updated_by' => $userId,
        ]);
    }

    public function canTransitionTo(string $transition): bool
    {
        return $transition === 'reopen';
    }
}
