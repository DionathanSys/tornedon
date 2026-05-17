<?php

namespace App\Services\Requisition;

use App\Enum\StockMovement\Type;
use App\Models\Requisition;
use App\Models\StockMovement;
use App\Services\ProductStock\ProductStockService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class RequisitionStockWorkflow
{
    use HandlesServiceResponse;

    public function __construct(
        private readonly RequisitionStockService $stockService,
        private readonly ProductStockService $productStockService,
    ) {}

    public function recreateReservationsIfNeeded(Requisition $requisition, int $userId): bool
    {
        $this->resetResponse();

        if (! $this->shouldRecreateReservations($requisition)) {
            return true;
        }

        $items = $this->stockService->pendingItems($requisition, withProduct: true);

        foreach ($items as $item) {
            if (! $item->product_id || ! $item->product?->has_stock_control) {
                continue;
            }

            $stock = $this->stockService->findProductStock($requisition, $item);

            if (! $stock) {
                Log::warning('RequisitionStockWorkflow: ProductStock nao encontrado ao recriar reserva', [
                    'product_id' => $item->product_id,
                    'item_id' => $item->id,
                    'requisition_id' => $requisition->id,
                ]);

                continue;
            }

            $movement = $this->stockService->createReservation(
                $requisition,
                $item,
                $userId,
                'Reserva recriada por re-encerramento - requisicao #' . $requisition->number,
            );

            Log::info('RequisitionStockWorkflow: Reserva recriada apos reabertura', [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'movement_id' => $movement->id,
                'requisition_id' => $requisition->id,
            ]);
        }

        return true;
    }

    public function releaseReservations(Requisition $requisition, int $userId): bool
    {
        $this->resetResponse();

        $items = $this->stockService->pendingItems($requisition, withProduct: true);

        if ($items->isEmpty()) {
            Log::info('RequisitionStockWorkflow: Nenhum item pendente de liberação', [
                'requisition_id' => $requisition->id,
            ]);

            return true;
        }

        foreach ($items as $item) {
            if (! $item->product_id || ! $item->product?->has_stock_control) {
                continue;
            }

            $stock = $this->stockService->findProductStock($requisition, $item);

            if (! $stock) {
                Log::warning('RequisitionStockWorkflow: ProductStock não encontrado', [
                    'product_id' => $item->product_id,
                    'item_id' => $item->id,
                    'requisition_id' => $requisition->id,
                ]);

                continue;
            }

            $release = $this->stockService->createReservationRelease(
                $requisition,
                $item,
                $userId,
                'Liberação de reserva — requisição #' . $requisition->number,
            );

            Log::info('RequisitionStockWorkflow: Reserva liberada', [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'release_id' => $release->id,
                'item_id' => $item->id,
                'requisition_id' => $requisition->id,
            ]);
        }

        return true;
    }

    public function processStockExits(Requisition $requisition, int $userId): bool
    {
        $this->resetResponse();

        $items = $this->stockService->pendingItems($requisition);

        if ($items->isEmpty()) {
            Log::info('RequisitionStockWorkflow: Nenhum item pendente de saída de estoque', [
                'requisition_id' => $requisition->id,
            ]);

            return true;
        }

        foreach ($items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = $item->product;

            if (! $product || ! $product->has_stock_control) {
                $this->stockService->markItemAsConsumed($item);
                continue;
            }

            $productStock = $this->stockService->findProductStock($requisition, $item);

            if (! $productStock) {
                throw new \RuntimeException('Estoque não encontrado para o produto #' . $item->product_id);
            }

            $exit = $this->stockService->createExit(
                $requisition,
                $item,
                $userId,
                'Saída por faturamento — requisição #' . $requisition->number,
            );

            $release = $this->stockService->createReservationRelease(
                $requisition,
                $item,
                $userId,
                'Liberação de reserva por faturamento — requisição #' . $requisition->number,
            );

            $this->stockService->markItemAsConsumed($item);

            Log::info('RequisitionStockWorkflow: Saída de estoque processada', [
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
                'exit_id' => $exit->id,
                'release_id' => $release->id,
                'requisition_id' => $requisition->id,
            ]);
        }

        $this->stockService->syncConsumptionFlags($requisition);

        return true;
    }

    public function hasSufficientStockForClose(Requisition $requisition, $item): bool
    {
        $stock = $this->productStockService->findByProductId($item->product_id, $requisition->company_id);

        if (! $stock || $stock->allow_negative) {
            return true;
        }

        $requestedQuantity = $item->resolvedBaseQuantity();
        $availableQuantity = (float) $stock->quantity_available;
        $reservedForItem = $this->stockService->resolveReservedQuantity($requisition, $item);
        $effectiveAvailable = $availableQuantity + $reservedForItem;

        Log::debug('RequisitionStockWorkflow: Effective availability resolved for close', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $requisition->id,
            'item_id' => $item->id,
            'product_id' => $item->product_id,
            'requested_quantity' => $requestedQuantity,
            'quantity_available' => $availableQuantity,
            'reserved_for_item' => $reservedForItem,
            'effective_available' => $effectiveAvailable,
        ]);

        return $effectiveAvailable >= $requestedQuantity;
    }

    private function shouldRecreateReservations(Requisition $requisition): bool
    {
        $netReleasedQuantity = (float) StockMovement::query()
            ->where('source_type', 'requisition')
            ->where('source_id', $requisition->id)
            ->whereIn('type', [
                Type::RESERVATION_RELEASE->value,
                Type::RESERVATION->value,
            ])
            ->get(['type', 'quantity', 'base_quantity'])
            ->sum(function (StockMovement $movement): float {
                $quantity = $movement->resolvedBaseQuantity();

                return $movement->type === Type::RESERVATION_RELEASE
                    ? $quantity
                    : -$quantity;
            });

        Log::debug('RequisitionStockWorkflow: Net released quantity', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $requisition->id,
            'net_released_quantity' => $netReleasedQuantity,
        ]);

        $shouldRecreate = $netReleasedQuantity > 0.0001;

        if (! $shouldRecreate && $requisition->stock_reserved === false) {
            Log::warning('RequisitionStockWorkflow: Flag indicava reabertura, mas nao ha liberacao pendente; recriacao ignorada', [
                'requisition_id' => $requisition->id,
                'stock_reserved' => $requisition->stock_reserved,
                'net_released_quantity' => $netReleasedQuantity,
            ]);
        }

        return $shouldRecreate;
    }
}
