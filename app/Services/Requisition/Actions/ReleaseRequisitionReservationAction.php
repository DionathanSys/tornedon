<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Services\ProductStock\ProductStockService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;

/**
 * Libera a reserva de estoque dos itens de uma requisição.
 *
 * Itera os itens ainda não consumidos e libera diretamente o quantity_reserved
 * via ProductStockService::updateReservation().
 *
 * A reserva foi criada pelos listeners (HandleStockReservation*) quando os itens
 * foram adicionados/atualizados — não há StockMovements do tipo RESERVATION.
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
            $productStockService = app(ProductStockService::class);

            $items = $requisition->items()
                ->where('stock_consumed', false)
                ->with('product')
                ->get();

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

                $released = $productStockService->updateReservation(
                    stock:         $stock,
                    quantityDelta: -(float) $item->quantity,
                    lastSalePrice: (float) ($item->unit_price ?? 0),
                    movementType:  Type::RESERVATION_RELEASE,
                    updatedBy:     $this->userId,
                );

                if (! $released) {
                    Log::error('ReleaseRequisitionReservationAction: Falha ao liberar reserva', [
                        'product_id'     => $item->product_id,
                        'item_id'        => $item->id,
                        'requisition_id' => $requisition->id,
                        'error'          => $productStockService->getMessage(),
                    ]);
                    $this->setError('Falha ao liberar reserva de estoque para produto #' . $item->product_id);
                    return false;
                }

                Log::info('ReleaseRequisitionReservationAction: Reserva liberada', [
                    'product_id'     => $item->product_id,
                    'quantity'       => $item->quantity,
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
