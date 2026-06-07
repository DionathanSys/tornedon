<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Status;
use App\Models\ProductionOrder;
use App\Services\Audit\AuditRecorder;
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
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($productionOrder);

            if (!$productionOrder->canComplete()) {
                $this->setError('Esta ordem de produção não pode ser concluída');
                return false;
            }

            if (! $productionOrder->items()->where('quantity_approved', '>', 0)->exists()) {
                $this->setError('Informe ao menos uma quantidade aprovada antes de concluir a produção.');
                return false;
            }

            DB::beginTransaction();

            // Update production order status
            $productionOrder->update([
                'status' => Status::COMPLETED->value,
                'completed_at' => now(),
                'updated_by' => $this->userId,
            ]);

            // 1. SEMPRE envia produtos aprovados para o estoque
            $stockHandler = new StockDestinationHandler();
            $stockResult = $stockHandler->handle($productionOrder, $this->userId);

            if (!$stockResult) {
                DB::rollBack();
                $this->setError('Erro ao registrar entrada de produção no estoque');
                return false;
            }

            // 2. Se destino é ENTREGA DIRETA, gera requisição automaticamente
            if ($productionOrder->destination_type === DestinationType::DIRECT_DELIVERY) {
                $requisitionAction = new GenerateRequisitionFromProductionAction($this->userId);
                $requisition = $requisitionAction->execute($productionOrder->fresh());

                if ($requisitionAction->hasError()) {
                    DB::rollBack();
                    $this->setError('Erro ao gerar requisição: ' . $requisitionAction->getMessage());
                    return false;
                }
            }

            DB::commit();
            $productionOrder->refresh();
            $audit->recordModelEvent(
                $productionOrder,
                'production_order.completed',
                "Ordem de produção #{$productionOrder->production_order_number} concluída",
                $before,
                $audit->snapshot($productionOrder),
                $this->userId,
            );
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
}
