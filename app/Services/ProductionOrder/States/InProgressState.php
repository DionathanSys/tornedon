<?php

namespace App\Services\ProductionOrder\States;

use App\Enum\ProductionOrder\Status;
use Illuminate\Support\Facades\Log;

class InProgressState extends ProductionOrderState
{
    public function sendToQC(): void
    {
        $this->productionOrder->update([
            'status' => Status::QC_CHECK,
        ]);

        Log::info('Ordem enviada para QC', [
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

        Log::info('Ordem de produção cancelada durante produção', [
            'production_order_id' => $this->productionOrder->id,
            'production_order_number' => $this->productionOrder->production_order_number,
        ]);
    }

    public function name(): string
    {
        return 'Em Produção';
    }
}
