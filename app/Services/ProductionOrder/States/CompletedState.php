<?php

namespace App\Services\ProductionOrder\States;

use App\Enum\ProductionOrder\Status;
use Illuminate\Support\Facades\Log;

class CompletedState extends ProductionOrderState
{
    public function name(): string
    {
        return 'Concluído';
    }

    public function invoice(int $invoiceId): void
    {
        Log::info('ProductionOrder: Faturando OP (concluída → faturada)', [
            'production_order_id' => $this->productionOrder->id,
            'invoice_id'          => $invoiceId,
        ]);

        $this->productionOrder->update([
            'status'     => Status::INVOICED,
            'invoice_id' => $invoiceId,
        ]);
    }
}
