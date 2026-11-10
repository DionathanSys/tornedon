<?php

namespace App\Services\Requisition;

use App\Models\Requisition;
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

        $items = $this->stockService->pendingItems($requisition, withProduct: true);
        $reservedByProduct = $this->stockService->reservedQuantitiesByProduct($requisition);

        foreach ($items as $item) {
            if (! $item->product_id || ! $item->product?->has_stock_control) {
                continue;
            }

            // Allocate the existing balance once, including reservations made by
            // items added after reopening. Only reserve the remaining deficit.
            $requestedQuantity = $item->resolvedBaseQuantity();
            $reservedQuantity = min($reservedByProduct[$item->product_id] ?? 0, $requestedQuantity);
            $reservedByProduct[$item->product_id] = ($reservedByProduct[$item->product_id] ?? 0) - $reservedQuantity;
            $missingQuantity = round($requestedQuantity - $reservedQuantity, 8);

            if ($missingQuantity < 0.0001) {
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
                'Reserva recriada por re-encerramento - requisição #'.$requisition->number,
                baseQuantity: $missingQuantity,
            );

            Log::info('RequisitionStockWorkflow: Reserva recriada após reabertura', [
                'product_id' => $item->product_id,
                'quantity' => $missingQuantity,
                'movement_id' => $movement->id,
                'requisition_id' => $requisition->id,
            ]);
        }

        return true;
    }

    public function pendingItems(Requisition $requisition, bool $withProduct = false)
    {
        return $this->stockService->pendingItems($requisition, $withProduct);
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
                'Liberação de reserva — requisição #'.$requisition->number,
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
            'metodo' => __METHOD__.'@'.__LINE__,
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

    public function syncFlags(Requisition $requisition): void
    {
        $this->stockService->syncConsumptionFlags($requisition);
    }
}
