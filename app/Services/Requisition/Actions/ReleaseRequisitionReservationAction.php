<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Services\ProductStock\ProductStockService;
use App\Services\Requisition\RequisitionStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Libera a reserva de estoque dos itens de uma requisição.
 *
 * Itera os itens ainda não consumidos e cria movimentações de RESERVATION_RELEASE
 * via StockMovementService, garantindo rastro de auditoria completo.
 *
 * Deve ser chamado ao cancelar (OPEN → CANCELLED) ou reabrir (CLOSED → OPEN) uma requisição.
 */
class ReleaseRequisitionReservationAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
    ) {}

    public function execute(Requisition $requisition): bool
    {
        try {
            $productStockService  = app(ProductStockService::class);
            $stockMovementService = app(StockMovementService::class);
            $stockService = app(RequisitionStockService::class);

            $items = $stockService->pendingItems($requisition, withProduct: true);

            if ($items->isEmpty()) {
                Log::info('ReleaseRequisitionReservationAction: Nenhum item pendente de liberação', [
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
                    continue;
                }

                $stock = $productStockService->findByProductId(
                    $item->product_id,
                    $requisition->company_id
                );

                if (! $stock) {
                    Log::warning('ReleaseRequisitionReservationAction: ProductStock não encontrado', [
                        'product_id'     => $item->product_id,
                        'item_id'        => $item->id,
                        'requisition_id' => $requisition->id,
                    ]);
                    continue;
                }

                $release = $stockMovementService->create([
                    'product_stock_id' => $stock->id,
                    'product_id'       => $item->product_id,
                    'company_id'       => $requisition->company_id,
                    'type'             => Type::RESERVATION_RELEASE->value,
                    'operational_unit' => $item->unit_of_measure ?? $product->unit?->value,
                    'operational_quantity' => (float) $item->quantity,
                    'base_unit'        => $product->unit?->value,
                    'base_quantity'    => $item->resolvedBaseQuantity(),
                    'conversion_factor_snapshot' => (float) ($item->conversion_factor_snapshot ?? 1),
                    'quantity'         => $item->resolvedBaseQuantity(),
                    'unit_price'       => (float) ($item->unit_price ?? 0),
                    'reason'           => 'Liberação de reserva — requisição #' . $requisition->number,
                    'source_type'      => 'requisition',
                    'source_id'        => $requisition->id,
                ], $this->userId);

                if (! $release) {
                    Log::error('ReleaseRequisitionReservationAction: Falha ao criar movimentação de liberação', [
                        'product_id'     => $item->product_id,
                        'item_id'        => $item->id,
                        'requisition_id' => $requisition->id,
                        'error'          => $stockMovementService->getMessage(),
                    ]);
                    $this->setError('Falha ao liberar reserva de estoque para produto #' . $item->product_id);
                    return false;
                }

                Log::info('ReleaseRequisitionReservationAction: Reserva liberada', [
                    'product_id'     => $item->product_id,
                    'quantity'       => $item->quantity,
                    'release_id'     => $release->id,
                    'item_id'        => $item->id,
                    'requisition_id' => $requisition->id,
                ]);
            }

            $this->setSuccess();
            return true;

        } catch (\Exception $e) {
            $this->setError('Erro ao liberar reservas da requisição: ' . $e->getMessage());

            Log::error('ReleaseRequisitionReservationAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return false;
        }
    }
}
