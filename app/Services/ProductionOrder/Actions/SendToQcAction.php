<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Services\Audit\AuditRecorder;
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
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($productionOrder);

            if ($productionOrder->status !== Status::IN_PROGRESS) {
                $this->setError('Apenas ordens em produção podem ser enviadas para QC');
                return false;
            }

            if (! $productionOrder->items()->where('quantity_produced', '>', 0)->exists()) {
                $this->setError('Registre quantidade produzida antes de enviar a ordem para o controle de qualidade.');
                return false;
            }

            $productionOrder->update([
                'status'     => Status::QC_CHECK->value,
                'updated_by' => $this->userId,
            ]);
            $productionOrder->refresh();

            $audit->recordModelEvent(
                $productionOrder,
                'production_order.sent_to_qc',
                "Ordem de produção #{$productionOrder->production_order_number} enviada para QC",
                $before,
                $audit->snapshot($productionOrder),
                $this->userId,
            );

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
