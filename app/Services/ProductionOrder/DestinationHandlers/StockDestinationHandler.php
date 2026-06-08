<?php

namespace App\Services\ProductionOrder\DestinationHandlers;

use App\Enum\StockMovement\Type;
use App\Models\ProductionOrder;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;

class StockDestinationHandler
{
    public function handle(ProductionOrder $productionOrder, int $userId): bool
    {
        try {
            $stockMovementService = app(StockMovementService::class);
            $productStockService  = app(ProductStockService::class);

            foreach ($productionOrder->items as $item) {
                if (! $item->product_id) {
                    Log::warning('StockDestinationHandler: Item sem product_id, ignorando', [
                        'production_order_item_id' => $item->id,
                        'description'              => $item->description,
                    ]);
                    continue;
                }

                $quantityToAdd = (float) $item->quantity_approved;

                if ($quantityToAdd <= 0) {
                    continue;
                }

                // Busca ou cria o ProductStock via service
                $productStock = $productStockService->findByProductId(
                    $item->product_id,
                    $productionOrder->company_id
                );

                if (! $productStock) {
                    $productStock = $productStockService->create([
                        'product_id'         => $item->product_id,
                        'company_id'         => $productionOrder->company_id,
                        'quantity_total'      => 0,
                        'quantity_reserved'   => 0,
                        'quantity_minimum'    => 0,
                        'average_cost'        => 0,
                        'is_active'           => true,
                        'allow_negative'      => false,
                    ], $userId);

                    if (! $productStock) {
                        Log::error('StockDestinationHandler: Falha ao criar ProductStock', [
                            'product_id'          => $item->product_id,
                            'production_order_id' => $productionOrder->id,
                            'error'               => $productStockService->getMessage(),
                        ]);
                        return false;
                    }
                }

                // Cria movimentação de ENTRADA via StockMovementService
                $movement = $stockMovementService->create([
                    'product_stock_id' => $productStock->id,
                    'product_id'       => $item->product_id,
                    'company_id'       => $productionOrder->company_id,
                    'type'             => Type::ENTRY->value,
                    'operational_unit' => $item->unit_of_measure,
                    'operational_quantity' => $quantityToAdd,
                    'base_unit'        => $item->product?->unit?->value,
                    'base_quantity'    => $item->resolvedApprovedBaseQuantity(),
                    'conversion_factor_snapshot' => (float) ($item->conversion_factor_snapshot ?? 1),
                    'quantity'         => $item->resolvedApprovedBaseQuantity(),
                    'unit_price'       => (float) ($item->unit_cost ?? 0),
                    'reason'           => 'Entrada de produção - OP #' . $productionOrder->production_order_number,
                    'source_type'      => 'production_order',
                    'source_id'        => $productionOrder->id,
                    'observations'     => "Produção concluída. Item: {$item->description}",
                ], $userId);

                if (! $movement) {
                    Log::error('StockDestinationHandler: Falha ao criar movimentação de estoque', [
                        'product_id'          => $item->product_id,
                        'production_order_id' => $productionOrder->id,
                        'error'               => $stockMovementService->getMessage(),
                    ]);
                    return false;
                }

                Log::info('StockDestinationHandler: Movimentação de estoque criada', [
                    'product_id'          => $item->product_id,
                    'quantity_added'      => $quantityToAdd,
                    'movement_id'         => $movement->id,
                    'production_order_id' => $productionOrder->id,
                ]);
            }

            return true;

        } catch (\Exception $e) {
            Log::error('StockDestinationHandler: Erro ao processar destino estoque', [
                'production_order_id' => $productionOrder->id,
                'error'               => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
