<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Consome o estoque dos itens de uma requisição, gerando movimentações de saída.
 * Deve ser chamado quando a requisição é encerrada (open → closed).
 */
class ConsumeRequisitionStockAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(Requisition $requisition): bool
    {
        try {
            $stockMovementService = app(StockMovementService::class);
            $productStockService  = app(ProductStockService::class);

            $items = $requisition->items()
                ->where('stock_consumed', false)
                ->get();

            if ($items->isEmpty()) {
                Log::info('ConsumeRequisitionStockAction: Nenhum item pendente de consumo', [
                    'requisition_id' => $requisition->id,
                ]);
                $this->setSuccess();
                return true;
            }

            foreach ($items as $item) {
                if (! $item->product_id) {
                    continue;
                }

                $product = $item->product;

                if (! $product || ! $product->has_stock_control) {
                    // Marca como consumido mesmo sem controle de estoque
                    $item->update([
                        'stock_consumed'    => true,
                        'stock_consumed_at' => now(),
                    ]);
                    continue;
                }

                $productStock = $productStockService->findByProductId(
                    $item->product_id,
                    $requisition->company_id
                );

                if (! $productStock) {
                    Log::warning('ConsumeRequisitionStockAction: ProductStock não encontrado', [
                        'product_id'     => $item->product_id,
                        'requisition_id' => $requisition->id,
                        'item_id'        => $item->id,
                    ]);
                    continue;
                }

                // Cria movimentação de SAÍDA via StockMovementService
                $movement = $stockMovementService->create([
                    'product_stock_id' => $productStock->id,
                    'product_id'       => $item->product_id,
                    'company_id'       => $requisition->company_id,
                    'type'             => Type::EXIT->value,
                    'quantity'         => (float) $item->quantity,
                    'unit_price'       => (float) ($item->unit_price ?? 0),
                    'reason'           => 'Saída por requisição #' . $requisition->number,
                    'source_type'      => 'requisition',
                    'source_id'        => $requisition->id,
                    'observations'     => $item->observations,
                ], $this->userId);

                if (! $movement) {
                    Log::error('ConsumeRequisitionStockAction: Falha ao criar movimentação de saída', [
                        'product_id'     => $item->product_id,
                        'requisition_id' => $requisition->id,
                        'item_id'        => $item->id,
                        'error'          => $stockMovementService->getMessage(),
                    ]);
                    $this->setError('Falha ao gerar movimentação de estoque para o item: ' . ($item->product->name ?? $item->product_id));
                    return false;
                }

                // Marca item como consumido
                $item->update([
                    'stock_consumed'    => true,
                    'stock_consumed_at' => now(),
                ]);

                Log::info('ConsumeRequisitionStockAction: Estoque consumido para item', [
                    'product_id'     => $item->product_id,
                    'quantity'       => $item->quantity,
                    'movement_id'    => $movement->id,
                    'requisition_id' => $requisition->id,
                ]);
            }

            // Marca a requisição como estoque consumido
            $requisition->update([
                'stock_consumed' => true,
            ]);

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao consumir estoque da requisição: ' . $e->getMessage());

            Log::error('ConsumeRequisitionStockAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
