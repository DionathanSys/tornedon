<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Services\ProductionOrder\DestinationHandlers\DirectDeliveryDestinationHandler;
use App\Services\ProductionOrder\DestinationHandlers\StockDestinationHandler;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CompleteProduction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ProductionOrder $productionOrder): bool
    {
        try {
            if (!$productionOrder->canComplete()) {
                $this->setError('Esta ordem de produção não pode ser concluída');
                return false;
            }

            DB::beginTransaction();

            // Update production order status
            $productionOrder->update([
                'status' => Status::COMPLETED->value,
                'completed_at' => now(),
                'updated_by' => $this->userId,
            ]);

            // Handle destination (stock or direct delivery)
            $handler = $this->getDestinationHandler($productionOrder->destination_type);
            $result = $handler->handle($productionOrder, $this->userId);

            if (!$result) {
                DB::rollBack();
                $this->setError('Erro ao processar destino da produção');
                return false;
            }

            DB::commit();
            $this->setSuccess();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->setError('Erro ao concluir produção: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'production_order_id' => $productionOrder->id,
            ]);
            
            return false;
        }
    }

    private function getDestinationHandler(DestinationType $destinationType): StockDestinationHandler|DirectDeliveryDestinationHandler
    {
        return match ($destinationType) {
            DestinationType::STOCK => new StockDestinationHandler(),
            DestinationType::DIRECT_DELIVERY => new DirectDeliveryDestinationHandler(),
        };
    }
}
