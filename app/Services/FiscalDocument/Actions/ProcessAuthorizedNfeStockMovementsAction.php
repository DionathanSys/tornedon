<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\StockMovement\Type;
use App\Models\FiscalDocument;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAuthorizedNfeStockMovementsAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): bool
    {
        try {
            if ($fiscalDocument->document_type !== DocumentModel::NFE) {
                $this->setSuccess();
                return true;
            }

            if (! $fiscalDocument->invoice_id) {
                $this->setSuccess();
                return true;
            }

            return DB::transaction(function () use ($fiscalDocument): bool {
                $invoice = $fiscalDocument->invoice()->with([
                    'requisitions.items.product',
                ])->first();

                if (! $invoice || $invoice->requisitions->isEmpty()) {
                    $this->setSuccess();
                    return true;
                }

                $userId = $this->resolveUserId($fiscalDocument, $invoice->created_by ?? null);

                foreach ($invoice->requisitions as $requisition) {
                    $this->processRequisition($requisition, $userId);

                    if ($this->hasError()) {
                        return false;
                    }
                }

                $this->setSuccess();
                return true;
            });
        } catch (\Throwable $e) {
            $this->setError('Erro ao processar estoque da NF-e autorizada: ' . $e->getMessage());

            Log::error('ProcessAuthorizedNfeStockMovementsAction: exceção', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    private function processRequisition(Requisition $requisition, int $userId): void
    {
        $stockMovementService = app(StockMovementService::class);
        $productStockService = app(ProductStockService::class);

        $items = $requisition->items
            ->where('stock_consumed', false)
            ->values();

        if ($items->isEmpty()) {
            return;
        }

        foreach ($items as $item) {
            if (! $item->product_id) {
                $this->markItemAsConsumed($item);
                continue;
            }

            if (! $item->product?->has_stock_control) {
                $this->markItemAsConsumed($item);
                continue;
            }

            $productStock = $productStockService->findByProductId($item->product_id, $requisition->company_id);

            if (! $productStock) {
                $this->setError('Estoque não encontrado para o produto #' . $item->product_id);
                return;
            }

            $baseData = [
                'product_stock_id'   => $productStock->id,
                'product_id'         => $item->product_id,
                'company_id'         => $requisition->company_id,
                'quantity'           => (float) $item->quantity,
                'unit_price'         => (float) ($item->unit_price ?? 0),
                'source_type'        => 'requisition',
                'source_id'          => $requisition->id,
                'observations'       => $item->observations,
            ];

            $releaseQuantity = $this->resolveReservedQuantity($requisition, $item);

            if ($releaseQuantity > 0.0001) {
                $release = $stockMovementService->create(array_merge($baseData, [
                    'type' => Type::RESERVATION_RELEASE->value,
                    'quantity' => $releaseQuantity,
                    'reason' => 'Liberação de reserva por NF-e autorizada - requisição #' . $requisition->number,
                ]), $userId);

                if (! $release) {
                    $this->setError(
                        'Falha ao liberar reserva do produto #' . $item->product_id . ': ' . $stockMovementService->getMessage()
                    );
                    return;
                }
            }

            $exit = $stockMovementService->create(array_merge($baseData, [
                'type' => Type::EXIT->value,
                'reason' => 'Saída por NF-e autorizada - requisição #' . $requisition->number,
            ]), $userId);

            if (! $exit) {
                $this->setError(
                    'Falha ao registrar saída do produto #' . $item->product_id . ': ' . $stockMovementService->getMessage()
                );
                return;
            }

            $this->markItemAsConsumed($item);
        }

        $hasPendingItems = $requisition->items()
            ->where('stock_consumed', false)
            ->exists();

        $requisition->update([
            'stock_consumed' => ! $hasPendingItems,
            'stock_reserved' => $hasPendingItems ? $requisition->stock_reserved : false,
        ]);
    }

    private function resolveReservedQuantity(Requisition $requisition, RequisitionItem $item): float
    {
        $reservedQuantity = (float) StockMovement::query()
            ->where('source_type', 'requisition')
            ->where('source_id', $requisition->id)
            ->where('product_id', $item->product_id)
            ->whereIn('type', [
                Type::RESERVATION->value,
                Type::RESERVATION_RELEASE->value,
            ])
            ->get(['type', 'quantity'])
            ->sum(function (StockMovement $movement): float {
                return $movement->type === Type::RESERVATION->value
                    ? (float) $movement->quantity
                    : -(float) $movement->quantity;
            });

        return min(max($reservedQuantity, 0), (float) $item->quantity);
    }

    private function markItemAsConsumed(RequisitionItem $item): void
    {
        $item->update([
            'stock_consumed' => true,
            'stock_consumed_at' => now(),
        ]);
    }

    private function resolveUserId(FiscalDocument $fiscalDocument, ?int $fallbackUserId = null): int
    {
        $userId = (int) ($fiscalDocument->confirmed_by
            ?? $fiscalDocument->updated_by
            ?? $fiscalDocument->created_by
            ?? $fallbackUserId
            ?? 0);

        if ($userId < 1) {
            throw new \RuntimeException('Usuário responsável não encontrado para registrar a movimentação de estoque da NF-e autorizada.');
        }

        return $userId;
    }
}
