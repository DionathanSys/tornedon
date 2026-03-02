<?php

namespace App\Services\ProductionOrder\DestinationHandlers;

use App\Enum\Requisition\Status;
use App\Enum\StockMovement\Type;
use App\Models\ProductionOrder;
use App\Services\ProductStock\ProductStockService;
use App\Services\Requisition\RequisitionService;
use App\Services\RequisitionItem\RequisitionItemService;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;

class DirectDeliveryDestinationHandler
{
    public function handle(ProductionOrder $productionOrder, int $userId): bool
    {
        try {
            // 1. Gera movimentação de ENTRADA no estoque (produção sempre entra no estoque)
            $stockHandler = new StockDestinationHandler();
            $stockResult  = $stockHandler->handle($productionOrder, $userId);

            if (! $stockResult) {
                Log::error('DirectDeliveryDestinationHandler: Falha ao gerar movimentação de estoque', [
                    'production_order_id' => $productionOrder->id,
                ]);
                return false;
            }

            // 2. Cria ou atualiza a requisição para entrega direta via services
            if ($productionOrder->requisition_id) {
                return $this->updateExistingRequisition($productionOrder, $userId);
            }

            return $this->createNewRequisition($productionOrder, $userId);

        } catch (\Exception $e) {
            Log::error('DirectDeliveryDestinationHandler: Erro ao processar entrega direta', [
                'production_order_id' => $productionOrder->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function updateExistingRequisition(ProductionOrder $productionOrder, int $userId): bool
    {
        $requisitionItemService = app(RequisitionItemService::class);
        $requisition = $productionOrder->requisition;

        if (! $requisition) {
            Log::warning('DirectDeliveryDestinationHandler: Requisição vinculada não encontrada', [
                'production_order_id' => $productionOrder->id,
                'requisition_id'      => $productionOrder->requisition_id,
            ]);
            return false;
        }

        foreach ($productionOrder->items as $item) {
            $requisitionItem = $requisition->items()
                ->where('product_id', $item->product_id)
                ->first();

            if ($requisitionItem) {
                $requisitionItemService->update($requisitionItem, [
                    'quantity' => $item->quantity_approved,
                ], $userId);
            }
        }

        return true;
    }

    private function createNewRequisition(ProductionOrder $productionOrder, int $userId): bool
    {
        $requisitionService     = app(RequisitionService::class);
        $requisitionItemService = app(RequisitionItemService::class);

        // Cria requisição via service
        $requisition = $requisitionService->create([
            'customer_id'         => $productionOrder->customer_id,
            'company_id'          => $productionOrder->company_id,
            'production_order_id' => $productionOrder->id,
            'sale_date'           => now()->toDateString(),
            'status'              => Status::OPEN->value,
            'observations'        => 'Gerado automaticamente pela ordem de produção #' . $productionOrder->production_order_number,
            'stock_consumed'      => false,
        ], $userId);

        if (! $requisition) {
            Log::error('DirectDeliveryDestinationHandler: Falha ao criar requisição', [
                'production_order_id' => $productionOrder->id,
                'error'               => $requisitionService->getMessage(),
            ]);
            return false;
        }

        // Cria itens da requisição via service
        foreach ($productionOrder->items as $item) {
            if ($item->quantity_approved <= 0) {
                continue;
            }

            $requisitionItem = $requisitionItemService->create([
                'requisition_id'    => $requisition->id,
                'product_id'        => $item->product_id,
                'unit_of_measure'   => $item->unit_of_measure,
                'quantity'          => $item->quantity_approved,
                'unit_price'        => 0,
                'discount_percentage' => 0,
                'discount_amount'   => 0,
                'observations'      => $item->description,
            ], $userId);

            if (! $requisitionItem) {
                Log::error('DirectDeliveryDestinationHandler: Falha ao criar item da requisição', [
                    'production_order_id' => $productionOrder->id,
                    'requisition_id'      => $requisition->id,
                    'product_id'          => $item->product_id,
                    'error'               => $requisitionItemService->getMessage(),
                ]);
                return false;
            }
        }

        // Vincula requisição à ordem de produção
        $productionOrder->update([
            'requisition_id' => $requisition->id,
        ]);

        Log::info('DirectDeliveryDestinationHandler: Requisição criada para entrega direta', [
            'production_order_id' => $productionOrder->id,
            'requisition_id'      => $requisition->id,
        ]);

        return true;
    }
}
