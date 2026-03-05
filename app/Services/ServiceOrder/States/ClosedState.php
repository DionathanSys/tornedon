<?php

namespace App\Services\ServiceOrder\States;

use App\Enum\ServiceOrder\State;
use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\Log;

/**
 * Estado: Encerrada
 * Transições permitidas: Faturar, Reabrir
 */
class ClosedState implements ServiceOrderState
{
    public function close(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['A ordem já está encerrada.']]
        );
    }

    public function invoice(ServiceOrder $order, int $userId): void
    {
        Log::info('ServiceOrder: Faturando ordem de serviço (encerrada → faturada)', [
            'service_order_id' => $order->id,
            'user_id'          => $userId,
        ]);

        $order->update([
            'status'     => State::INVOICED,
            'updated_by' => $userId,
        ]);
    }

    public function cancel(ServiceOrder $order, int $userId): void
    {
        throw new DomainValidationException(
            ['status' => ['Não é possível cancelar uma ordem encerrada. Reabra-a antes de cancelar.']]
        );
    }

    public function reopen(ServiceOrder $order, int $userId): void
    {
        Log::info('ServiceOrder: Reabrindo ordem de serviço (encerrada → aberta)', [
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
        return in_array($transition, ['invoice', 'reopen']);
    }

    public function canEdit(): bool
    {
        return false;
    }
}
