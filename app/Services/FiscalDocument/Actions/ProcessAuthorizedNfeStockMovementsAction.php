<?php

namespace App\Services\FiscalDocument\Actions;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\StockMovement\Type;
use App\Models\FiscalDocument;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\ProductStock\ProductStockService;
use App\Services\Requisition\RequisitionStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAuthorizedNfeStockMovementsAction
{
    use HandlesActionResponse;

    public function execute(FiscalDocument $fiscalDocument): bool
    {
        Log::debug('ProcessAuthorizedNfeStockMovementsAction: executando', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'fiscal_document_id' => $fiscalDocument->id,
            'key'                => 'TEST:BAIXA_ESTOQUE',
        ]);

        try {
            if ($fiscalDocument->document_type !== DocumentModel::NFE) {
                Log::info('ProcessAuthorizedNfeStockMovementsAction: não é NFE', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);
                $this->setSuccess();
                return true;
            }

            if (! $fiscalDocument->invoice_id) {
                Log::info('ProcessAuthorizedNfeStockMovementsAction: sem invoice', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);
                $this->setSuccess();
                return true;
            }

            return DB::transaction(function () use ($fiscalDocument): bool {
                $invoice = $fiscalDocument->invoice()->with([
                    'requisitions.items.product',
                ])->first();

                if (! $invoice || $invoice->requisitions->isEmpty()) {

                    Log::debug('ProcessAuthorizedNfeStockMovementsAction: sem requisições', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'key'                => 'TEST:BAIXA_ESTOQUE',
                    ]);

                    $this->setSuccess();
                    return true;
                }

                $userId = $this->resolveUserId($fiscalDocument, $invoice->created_by ?? null);

                foreach ($invoice->requisitions as $requisition) {

                    Log::debug('ProcessAuthorizedNfeStockMovementsAction: executando', [
                        'metodo' => __METHOD__ . '@' . __LINE__,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'requisition_id' => $requisition->id,
                        'key'                => 'TEST:BAIXA_ESTOQUE',
                    ]);

                    $this->processRequisition($requisition, $userId);

                    if ($this->hasError()) {

                        Log::debug('ProcessAuthorizedNfeStockMovementsAction: erro', [
                            'metodo' => __METHOD__ . '@' . __LINE__,    
                            'fiscal_document_id' => $fiscalDocument->id,
                            'requisition_id' => $requisition->id,
                            'key'                => 'TEST:BAIXA_ESTOQUE',
                        ]);

                        return false;
                    }
                }

                $this->setSuccess();

                Log::debug('ProcessAuthorizedNfeStockMovementsAction: executando', [
                    'metodo' => __METHOD__ . '@' . __LINE__,    
                    'fiscal_document_id' => $fiscalDocument->id,
                    'key'                => 'TEST:BAIXA_ESTOQUE',
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            $this->setError('Erro ao processar estoque da NF-e autorizada: ' . $e->getMessage());

            Log::error('ProcessAuthorizedNfeStockMovementsAction: exceção', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'fiscal_document_id' => $fiscalDocument->id,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'key'                => 'TEST:BAIXA_ESTOQUE',
            ]);

            return false;
        }
    }

    private function processRequisition(Requisition $requisition, int $userId): void
    {
        Log::debug('ProcessAuthorizedNfeStockMovementsAction: executando', [
            'metodo' => __METHOD__ . '@' . __LINE__,    
            'requisition_id' => $requisition->id,
            'key'                => 'TEST:BAIXA_ESTOQUE',
        ]);

        $stockMovementService = app(StockMovementService::class);
        $productStockService = app(ProductStockService::class);
        $stockService = app(RequisitionStockService::class);

        $items = $stockService->pendingItems($requisition, withProduct: true);

        Log::info('ProcessAuthorizedNfeStockMovementsAction: itens', [
            'metodo'            => __METHOD__ . '@' . __LINE__,    
            'requisition_id'    => $requisition->id,
            'items'             => $items,
        ]);

        if ($items->isEmpty()) {
            Log::debug('ProcessAuthorizedNfeStockMovementsAction: sem itens', [
                'metodo' => __METHOD__ . '@' . __LINE__,    
                'requisition_id' => $requisition->id,
                'key'                => 'TEST:BAIXA_ESTOQUE',
            ]);
            return;
        }

        foreach ($items as $item) {
            if (! $item->product_id) {
                Log::debug('ProcessAuthorizedNfeStockMovementsAction: sem produto', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,    
                    'requisition_id'    => $requisition->id,
                    'item'              => $item,
                ]);
                $stockService->markItemAsConsumed($item);
                continue;
            }

            if (! $item->product?->has_stock_control) {
                Log::debug('ProcessAuthorizedNfeStockMovementsAction: sem controle de estoque', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,    
                    'requisition_id'    => $requisition->id,
                    'item'              => $item,
                ]);
                $stockService->markItemAsConsumed($item);
                continue;
            }

            $productStock = $productStockService->findByProductId($item->product_id, $requisition->company_id);

            if (! $productStock) {
                Log::debug('ProcessAuthorizedNfeStockMovementsAction: estoque não encontrado', [
                    'metodo'            => __METHOD__ . '@' . __LINE__,    
                    'requisition_id'    => $requisition->id,
                    'item'              => $item,
                ]);
                $this->setError('Estoque não encontrado para o produto #' . $item->product_id);
                return;
            }

            $baseData = [
                'product_stock_id'   => $productStock->id,
                'product_id'         => $item->product_id,
                'company_id'         => $requisition->company_id,
                'operational_unit'   => $item->unit_of_measure ?? $item->product?->unit?->value,
                'operational_quantity' => (float) $item->quantity,
                'base_unit'         => $item->product?->unit?->value,
                'base_quantity'     => $item->resolvedBaseQuantity(),
                'conversion_factor_snapshot' => (float) ($item->conversion_factor_snapshot ?? 1),
                'quantity'           => $item->resolvedBaseQuantity(),
                'unit_price'         => (float) ($item->unit_price ?? 0),
                'source_type'        => 'requisition_item',
                'source_id'          => $item->id,
                'observations'       => $item->observations,
            ];

            $releaseQuantity = $stockService->resolveReservedQuantity($requisition, $item);

            if ($releaseQuantity > 0.0001) {

                $release = $stockMovementService->create(array_merge($baseData, [
                    'type' => Type::RESERVATION_RELEASE->value,
                    'operational_unit' => $item->unit_of_measure ?? $item->product?->unit?->value,
                    'operational_quantity' => (float) $item->quantity,
                    'base_unit' => $item->product?->unit?->value,
                    'base_quantity' => $releaseQuantity,
                    'conversion_factor_snapshot' => (float) ($item->conversion_factor_snapshot ?? 1),
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

            $exit = $this->hasItemExit($item)
                ? true
                : $stockMovementService->create(array_merge($baseData, [
                    'type' => Type::EXIT->value,
                    'reason' => 'Saída por NF-e autorizada - requisição #' . $requisition->number,
                ]), $userId);

            if (! $exit) {
                $this->setError(
                    'Falha ao registrar saída do produto #' . $item->product_id . ': ' . $stockMovementService->getMessage()
                );
                return;
            }

            $stockService->markItemAsConsumed($item);
        }

        $stockService->syncConsumptionFlags($requisition);

        $hasPendingItems = $requisition->items()
            ->whereNull('stock_consumed_at')
            ->exists();

        Log::debug('ProcessAuthorizedNfeStockMovementsAction: executando', [
            'metodo' => __METHOD__ . '@' . __LINE__,    
            'requisition_id' => $requisition->id,
            'hasPendingItems' => $hasPendingItems,
            'stock_reserved' => $requisition->stock_reserved,
            'key'                => 'TEST:BAIXA_ESTOQUE',
        ]);
    }

    private function hasItemExit(RequisitionItem $item): bool
    {
        return StockMovement::query()
            ->where('source_type', 'requisition_item')
            ->where('source_id', $item->id)
            ->where('type', Type::EXIT->value)
            ->exists();
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

    private function resolveItemBaseQuantity(RequisitionItem $item): float
    {
        return $item->resolvedBaseQuantity();
    }
}
