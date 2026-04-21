<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Services\Audit\AuditRecorder;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class ReturnToProductionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ProductionOrder $productionOrder): bool
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($productionOrder);

            if ($productionOrder->status !== Status::QC_CHECK) {
                $this->setError('Apenas ordens em controle de qualidade podem retornar para produção');
                return false;
            }

            $productionOrder->update([
                'status'     => Status::IN_PROGRESS->value,
                'updated_by' => $this->userId,
            ]);
            $productionOrder->refresh();

            $audit->recordModelEvent(
                $productionOrder,
                'production_order.returned',
                "Ordem de produção #{$productionOrder->production_order_number} retornou para produção",
                $before,
                $audit->snapshot($productionOrder),
                $this->userId,
            );

            Log::info('ReturnToProductionAction: Ordem retornada para produção após QC', [
                'production_order_id'     => $productionOrder->id,
                'production_order_number' => $productionOrder->production_order_number,
                'user_id'                 => $this->userId,
            ]);

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao retornar para produção: ' . $e->getMessage());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code'          => $this->getErrorCode(),
                'message'             => $e->getMessage(),
                'production_order_id' => $productionOrder->id,
            ]);

            return false;
        }
    }
}
