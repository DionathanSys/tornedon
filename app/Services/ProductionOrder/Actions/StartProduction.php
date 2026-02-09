<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class StartProduction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ProductionOrder $productionOrder): bool
    {
        try {
            if (!$productionOrder->canStart()) {
                $this->setError('Esta ordem de produção não pode ser iniciada');
                return false;
            }

            $productionOrder->update([
                'status' => Status::IN_PROGRESS->value,
                'started_at' => now(),
                'updated_by' => $this->userId,
            ]);

            $this->setSuccess();
            return true;
            
        } catch (\Exception $e) {
            $this->setError('Erro ao iniciar produção: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'production_order_id' => $productionOrder->id,
            ]);
            
            return false;
        }
    }
}
