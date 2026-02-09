<?php

namespace App\Services\ProductionOrder\Actions;

use App\Models\ProductionOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateProgress
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ProductionOrder $productionOrder, array $itemsProgress): bool
    {
        try {
            if (!$productionOrder->isInProgress()) {
                $this->setError('Apenas ordens em produção podem ter progresso atualizado');
                return false;
            }

            DB::beginTransaction();

            foreach ($itemsProgress as $itemId => $progress) {
                $item = $productionOrder->items()->find($itemId);
                
                if (!$item) {
                    continue;
                }

                $item->updateProductionQuantities(
                    produced: $progress['quantity_produced'] ?? null,
                    approved: $progress['quantity_approved'] ?? null,
                    rejected: $progress['quantity_rejected'] ?? null
                );

                if (isset($progress['production_notes'])) {
                    $item->production_notes = $progress['production_notes'];
                }

                if (isset($progress['actual_production_hours'])) {
                    $item->actual_production_hours = $progress['actual_production_hours'];
                }

                $item->save();
            }

            // Update total actual hours
            $totalActualHours = $productionOrder->items->sum('actual_production_hours');
            $productionOrder->update([
                'total_actual_hours' => $totalActualHours,
                'updated_by' => $this->userId,
            ]);

            DB::commit();
            $this->setSuccess();
            return true;
            
        } catch (\InvalidArgumentException $e) {
            DB::rollBack();
            $this->setError($e->getMessage());
            return false;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->setError('Erro ao atualizar progresso: ' . $e->getMessage());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'production_order_id' => $productionOrder->id,
            ]);
            
            return false;
        }
    }
}
