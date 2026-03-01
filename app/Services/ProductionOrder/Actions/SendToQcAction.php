<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class SendToQcAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(ProductionOrder $productionOrder): bool
    {
        try {
            if ($productionOrder->status !== Status::IN_PROGRESS) {
                $this->setError('Apenas ordens em produção podem ser enviadas para QC');
                return false;
            }

            $productionOrder->update([
                'status'     => Status::QC_CHECK->value,
                'updated_by' => $this->userId,
            ]);

            Log::info('SendToQcAction: Ordem enviada para controle de qualidade', [
                'production_order_id'     => $productionOrder->id,
                'production_order_number' => $productionOrder->production_order_number,
                'user_id'                 => $this->userId,
            ]);

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao enviar para QC: ' . $e->getMessage());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code'          => $this->getErrorCode(),
                'message'             => $e->getMessage(),
                'production_order_id' => $productionOrder->id,
            ]);

            return false;
        }
    }
}
