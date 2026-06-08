<?php

namespace App\Services\ProductionOrder\Actions;

use App\Enum\Requisition\Status;
use App\Models\ProductionOrder;
use App\Models\Requisition;
use App\Services\Requisition\RequisitionService;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

class GenerateRequisitionFromProductionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    /**
     * Gera uma requisição a partir de uma ordem de produção concluída.
     * Se a PO já possuir uma requisição vinculada, atualiza os itens existentes.
     *
     * @param ProductionOrder $productionOrder
     * @return Requisition|null
     */
    public function execute(ProductionOrder $productionOrder): ?Requisition
    {
        try {
            if (! $productionOrder->isCompleted()) {
                $this->setError('Apenas ordens de produção concluídas podem gerar requisição');
                return null;
            }

            $approvedItems = $productionOrder->items->filter(fn($item) => $item->quantity_approved > 0);

            if ($approvedItems->isEmpty()) {
                $this->setError('Nenhum item aprovado para gerar requisição');
                return null;
            }

            // Se já possui requisição, atualiza
            if ($productionOrder->requisition()->exists()) {
                $requisition = $this->updateExistingRequisition($productionOrder, $approvedItems);

                if (! $requisition) {
                    return null;
                }

                $this->setSuccess();
                return $requisition;
            }

            // Cria nova requisição
            $requisition = $this->createNewRequisition($productionOrder, $approvedItems);

            if (! $requisition) {
                return null;
            }

            $this->setSuccess();
            return $requisition;

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar requisição a partir da produção: ' . $e->getMessage());

            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code'          => $this->getErrorCode(),
                'message'             => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
                'production_order_id' => $productionOrder->id,
            ]);

            return null;
        }
    }

    private function createNewRequisition(ProductionOrder $productionOrder, $approvedItems): ?Requisition
    {
        $requisitionService     = app(RequisitionService::class);
        $requisitionItemService = app(RequisitionItemService::class);

        $requisition = $requisitionService->create([
            'customer_id'         => $productionOrder->customer_id,
            'company_id'          => $productionOrder->company_id,
            'production_order_id' => $productionOrder->id,
            'sale_date'           => now()->toDateString(),
            'status'              => Status::OPEN->value,
            'observations'        => 'Gerado automaticamente pela ordem de produção #' . $productionOrder->production_order_number,
            'stock_consumed'      => false,
        ], $this->userId);

        if (! $requisition) {
            $this->setError('Falha ao criar requisição: ' . $requisitionService->getMessage());

            Log::error('GenerateRequisitionFromProductionAction: Falha ao criar requisição', [
                'production_order_id' => $productionOrder->id,
                'error'               => $requisitionService->getMessage(),
            ]);

            return null;
        }

        // Cria itens da requisição
        foreach ($approvedItems as $item) {
                $requisitionItem = $requisitionItemService->create([
                    'requisition_id'      => $requisition->id,
                    'product_id'          => $item->product_id,
                    'unit_of_measure'     => $item->unit_of_measure,
                    'quantity'            => $item->quantity_approved,
                'unit_price'          => (float) ($item->unit_price ?? 0),
                'unit_cost'           => (float) ($item->unit_cost ?? 0),
                'discount_percentage' => 0,
                'discount_amount'     => 0,
                'observations'        => $item->description,
            ], $this->userId);

            if (! $requisitionItem) {
                $this->setError('Falha ao criar item da requisição: ' . $requisitionItemService->getMessage());

                Log::error('GenerateRequisitionFromProductionAction: Falha ao criar item da requisição', [
                    'production_order_id' => $productionOrder->id,
                    'requisition_id'      => $requisition->id,
                    'product_id'          => $item->product_id,
                    'error'               => $requisitionItemService->getMessage(),
                ]);

                return null;
            }
        }

        Log::info('GenerateRequisitionFromProductionAction: Requisição criada com sucesso', [
            'production_order_id' => $productionOrder->id,
            'requisition_id'      => $requisition->id,
            'total_items'         => $approvedItems->count(),
        ]);

        return $requisition;
    }

    private function updateExistingRequisition(ProductionOrder $productionOrder, $approvedItems): ?Requisition
    {
        $requisitionItemService = app(RequisitionItemService::class);
        $requisition = $productionOrder->requisition;

        if (! $requisition) {
            $this->setError('Requisição vinculada não encontrada');

            Log::warning('GenerateRequisitionFromProductionAction: Requisição vinculada não encontrada', [
                'production_order_id' => $productionOrder->id,
                'requisition_id'      => $productionOrder->requisition()->value('id'),
            ]);

            return null;
        }

        foreach ($approvedItems as $item) {
            $requisitionItem = $requisition->items()
                ->where('product_id', $item->product_id)
                ->first();

            if ($requisitionItem) {
                $requisitionItemService->update($requisitionItem, [
                    'unit_of_measure' => $item->unit_of_measure,
                    'quantity' => $item->quantity_approved,
                    'unit_price' => (float) ($item->unit_price ?? 0),
                    'unit_cost' => (float) ($item->unit_cost ?? 0),
                ], $this->userId);
            } else {
                $requisitionItemService->create([
                    'requisition_id'      => $requisition->id,
                    'product_id'          => $item->product_id,
                    'unit_of_measure'     => $item->unit_of_measure,
                    'quantity'            => $item->quantity_approved,
                    'unit_price'          => (float) ($item->unit_price ?? 0),
                    'unit_cost'           => (float) ($item->unit_cost ?? 0),
                    'discount_percentage' => 0,
                    'discount_amount'     => 0,
                    'observations'        => $item->description,
                ], $this->userId);
            }
        }

        Log::info('GenerateRequisitionFromProductionAction: Requisição atualizada com sucesso', [
            'production_order_id' => $productionOrder->id,
            'requisition_id'      => $requisition->id,
        ]);

        return $requisition;
    }
}
