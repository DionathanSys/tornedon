<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Reserva o estoque dos itens de uma requisição, gerando movimentações de RESERVATION.
 *
 * Aumenta quantity_reserved no ProductStock sem reduzir quantity_available.
 * Deve ser chamado quando a requisição é encerrada (open → closed).
 * A saída física ocorre apenas no faturamento, via InvoiceRequisitionAction.
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
                Log::info('ConsumeRequisitionStockAction: Nenhum item pendente de reserva', [
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
                    // Produtos sem controle de estoque não geram reserva
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

                // Cria movimentação de RESERVATION via StockMovementService
                $movement = $stockMovementService->create([
                    'product_stock_id' => $productStock->id,
                    'product_id'       => $item->product_id,
                    'company_id'       => $requisition->company_id,
                    'type'             => Type::RESERVATION->value,
                    'quantity'         => (float) $item->quantity,
                    'unit_price'       => (float) ($item->unit_price ?? 0),
                    'reason'           => 'Reserva por requisição #' . $requisition->number,
                    'source_type'      => 'requisition',
                    'source_id'        => $requisition->id,
                    'observations'     => $item->observations,
                ], $this->userId);

                if (! $movement) {
                    Log::error('ConsumeRequisitionStockAction: Falha ao criar movimentação de reserva', [
                        'product_id'     => $item->product_id,
                        'requisition_id' => $requisition->id,
                        'item_id'        => $item->id,
                        'error'          => $stockMovementService->getMessage(),
                    ]);
                    $this->setError('Falha ao reservar estoque para o item: ' . ($item->product->name ?? $item->product_id));
                    return false;
                }

                Log::info('ConsumeRequisitionStockAction: Estoque reservado para item', [
                    'product_id'     => $item->product_id,
                    'quantity'       => $item->quantity,
                    'movement_id'    => $movement->id,
                    'requisition_id' => $requisition->id,
                ]);
            }

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao reservar estoque da requisição: ' . $e->getMessage());

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
