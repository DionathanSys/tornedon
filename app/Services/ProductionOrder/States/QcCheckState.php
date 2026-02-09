<?php

namespace App\Services\ProductionOrder\States;

use App\Enum\ProductionOrder\Status;
use Illuminate\Support\Facades\Log;

class QcCheckState extends ProductionOrderState
{
    public function complete(): void
    {
        $this->productionOrder->update([
            'status' => Status::COMPLETED,
            'completed_at' => now(),
        ]);

        Log::info('Ordem de produção concluída', [
            'production_order_id' => $this->productionOrder->id,
            'production_order_number' => $this->productionOrder->production_order_number,
        ]);
    }

    public function returnToProduction(): void
    {
        $this->productionOrder->update([
            'status' => Status::IN_PROGRESS,
        ]);

        Log::info('Ordem retornada para produção após QC', [
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

        Log::info('Ordem de produção cancelada durante QC', [
            'production_order_id' => $this->productionOrder->id,
            'production_order_number' => $this->productionOrder->production_order_number,
        ]);
    }

    public function name(): string
    {
        return 'Controle de Qualidade';
    }
}
