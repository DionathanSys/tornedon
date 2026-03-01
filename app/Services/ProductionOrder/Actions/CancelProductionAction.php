<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class CancelProductionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ProductionOrder $productionOrder): bool
    {
        try {
            if (!$productionOrder->canCancel()) {
                $this->setError('Esta ordem de produção não pode ser cancelada');
                return false;
            }

            $productionOrder->update([
                'status'       => Status::CANCELLED->value,
                'cancelled_at' => now(),
                'updated_by'   => $this->userId,
            ]);

            Log::info('CancelProductionAction: Ordem de produção cancelada', [
                'production_order_id'     => $productionOrder->id,
                'production_order_number' => $productionOrder->production_order_number,
                'previous_status'         => $productionOrder->getOriginal('status'),
                'user_id'                 => $this->userId,
            ]);

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao cancelar ordem de produção: ' . $e->getMessage());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code'          => $this->getErrorCode(),
                'message'             => $e->getMessage(),
                'production_order_id' => $productionOrder->id,
            ]);

            return false;
        }
    }
}
