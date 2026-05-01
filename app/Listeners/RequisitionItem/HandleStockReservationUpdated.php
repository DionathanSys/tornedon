<?php

namespace App\Listeners\RequisitionItem;

use App\Enum\StockMovement\Type;
use App\Events\RequisitionItem\RequisitionItemUpdated;
use App\Models\ProductStock;
use App\Models\RequisitionItem;
use App\Services\StockMovement\StockMovementService;
use Illuminate\Support\Facades\Log;

class HandleStockReservationUpdated
{
    public function __construct(
        private StockMovementService $stockMovementService,
    ) {}

    public function handle(RequisitionItemUpdated $event): void
    {
        $item       = $event->item;
        $newProduct = $item->product;

        $productChanged = $event->oldProductId !== $item->product_id;
        $quantityDelta  = (float) $item->quantity - $event->oldQuantity;

        // Se o produto mudou → libera reserva do produto antigo e reserva no novo
        if ($productChanged) {
            $this->releaseOldProductReservation(
                item:         $item,
                oldProductId: $event->oldProductId,
                quantity:     $event->oldQuantity,
                updatedBy:    $event->updatedBy,
            );

            if ($newProduct && $newProduct->has_stock_control) {
                $newStock = ProductStock::where('product_id', $newProduct->id)
                    ->where('company_id', $newProduct->company_id)
                    ->first();

                if ($newStock) {
                    $movement = $this->stockMovementService->create([
                        'product_stock_id' => $newStock->id,
                        'product_id'       => $newProduct->id,
                        'company_id'       => $newStock->company_id,
                        'type'             => Type::RESERVATION->value,
                        'operational_unit' => $item->unit_of_measure ?? $newProduct->unit?->value,
                        'quantity'         => (float) $item->quantity,
                        'unit_price'       => (float) ($item->unit_price ?? 0),
                        'reason'           => 'Reserva por alteração de produto no item de requisição',
                        'source_type'      => 'requisition_item',
                        'source_id'        => $item->id,
                    ], $event->updatedBy);

                    if (! $movement) {
                        Log::error('HandleStockReservationUpdated: Erro ao reservar estoque do novo produto', [
                            'metodo'     => __METHOD__ . '@' . __LINE__,
                            'product_id' => $newProduct->id,
                            'item_id'    => $item->id,
                            'error'      => $this->stockMovementService->getMessage(),
                        ]);
                    }
                }
            }

            return;
        }

        // Mesmo produto → ajusta pelo delta de quantidade
        if ($quantityDelta == 0 && (float) $item->unit_price === $event->oldUnitPrice) {
            return;
        }

        if (! $newProduct || ! $newProduct->has_stock_control) {
            return;
        }

        if ($quantityDelta == 0) {
            return; // apenas preço mudou — sem impacto na reserva
        }

        $stock = ProductStock::where('product_id', $newProduct->id)
            ->where('company_id', $newProduct->company_id)
            ->first();

        if (! $stock) {
            Log::warning('HandleStockReservationUpdated: Estoque não encontrado para o produto', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $newProduct->id,
                'item_id'    => $item->id,
            ]);
            return;
        }

        $movementType = $quantityDelta > 0 ? Type::RESERVATION : Type::RESERVATION_RELEASE;

        $movement = $this->stockMovementService->create([
            'product_stock_id' => $stock->id,
            'product_id'       => $newProduct->id,
            'company_id'       => $stock->company_id,
            'type'             => $movementType->value,
            'operational_unit' => $item->unit_of_measure ?? $newProduct->unit?->value,
            'quantity'         => abs($quantityDelta),
            'unit_price'       => (float) ($item->unit_price ?? 0),
            'reason'           => 'Ajuste de reserva por atualização de quantidade no item de requisição',
            'source_type'      => 'requisition_item',
            'source_id'        => $item->id,
        ], $event->updatedBy);

        if (! $movement) {
            Log::error('HandleStockReservationUpdated: Erro ao ajustar reserva de estoque', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'product_id' => $newProduct->id,
                'item_id'    => $item->id,
                'error'      => $this->stockMovementService->getMessage(),
            ]);
        }
    }

    private function releaseOldProductReservation(
        RequisitionItem $item,
        int $oldProductId,
        float $quantity,
        int $updatedBy,
    ): void {
        $oldStock = ProductStock::where('product_id', $oldProductId)->first();

        if (! $oldStock) {
            return;
        }

        $movement = $this->stockMovementService->create([
            'product_stock_id' => $oldStock->id,
            'product_id'       => $oldProductId,
            'company_id'       => $oldStock->company_id,
            'type'             => Type::RESERVATION_RELEASE->value,
            'operational_unit' => $item->unit_of_measure ?? $oldStock->product?->unit?->value,
            'quantity'         => $quantity,
            'unit_price'       => (float) ($oldStock->last_sale_price ?? 0),
            'reason'           => 'Liberação de reserva por troca de produto no item de requisição',
            'source_type'      => 'requisition_item',
            'source_id'        => $item->id,
        ], $updatedBy);

        if (! $movement) {
            Log::error('HandleStockReservationUpdated: Erro ao liberar estoque do produto antigo', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'old_product_id' => $oldProductId,
                'error'          => $this->stockMovementService->getMessage(),
            ]);
        }
    }
}
