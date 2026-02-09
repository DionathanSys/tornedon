<?php

namespace App\Services\ProductionOrder\States;

use App\Enum\ProductionOrder\Status;
use Illuminate\Support\Facades\Log;

class QueuedState extends ProductionOrderState
{
    public function start(): void
    {
        $this->productionOrder->update([
            'status' => Status::IN_PROGRESS,
            'started_at' => now(),
        ]);

        Log::info('Produção iniciada', [
            'production_order_id' => $this->productionOrder->id,
            'production_order_number' => $this->productionOrder->production_order_number,
        ]);
    }

    public function cancel(): void
    {
        $this->productionOrder->update([
            'status' => Status::CANCELLED,
            'cancelled_at' => now(),
        ]);

        Log::info('Ordem de produção cancelada', [
            'production_order_id' => $this->productionOrder->id,
            'production_order_number' => $this->productionOrder->production_order_number,
        ]);
    }

    public function name(): string
    {
        return 'Na Fila';
    }
}
