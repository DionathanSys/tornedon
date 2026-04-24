<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\StockMovement;
use App\Services\Audit\AuditRecorder;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CloseRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    public function execute(Requisition $requisition): ?Requisition
    {
        try {
            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($requisition);

            Log::debug('CloseRequisitionAction: Encerrando requisicao', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $this->userId,
                'stock_reserved' => $requisition->stock_reserved,
            ]);

            return DB::transaction(function () use ($requisition, $audit, $before) {
                $productStockService = app(ProductStockService::class);
                $items = $requisition
                    ->items()
                    ->whereNull('stock_consumed_at')
                    ->with('product')
                    ->get();

                foreach ($items as $item) {
                    Log::debug('CloseRequisitionAction: Item', [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $requisition->id,
                        'item_id'        => $item->id,
                        'product_id'     => $item->product_id,
                        'product_name'   => $item->product->name ?? 'N/A',
                        'has_stock_control' => $item->product?->has_stock_control,
                    ]);
                    if (! $item->product_id || ! $item->product?->has_stock_control) {
                        continue;
                    }

                    if (! $this->hasSufficientStockForClose($requisition, $item, $productStockService)) {
                        $this->setError(sprintf(
                            'Saldo insuficiente para "%s". Verifique o estoque antes de encerrar.',
                            $item->product->name ?? "Produto #{$item->product_id}"
                        ));

                        return null;
                    }
                }

                if ($this->shouldRecreateReservations($requisition)) {
                    Log::debug('CloseRequisitionAction: Recreating reservations', [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $requisition->id,
                        'user_id'        => $this->userId,
                    ]);
                    $this->recreateReservations($requisition, $items);
                }

                $requisition->state()->close($requisition, $this->userId);
                $requisition->update(['stock_reserved' => true]);
                $requisition->refresh();
                $audit->recordModelEvent(
                    $requisition,
                    'requisition.closed',
                    "Requisição #{$requisition->number} encerrada",
                    $before,
                    $audit->snapshot($requisition),
                    $this->userId,
                );

                $this->setSuccess();

                Log::debug('CloseRequisitionAction: Requisition closed', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'user_id'        => $this->userId,
                ]);

                return $requisition;
            });
        } catch (DomainValidationException $e) {
            $this->setError('Transicao invalida', $e->errors);

            Log::warning('CloseRequisitionAction: Transicao invalida', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'errors'         => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao encerrar requisicao no banco de dados');

            Log::error('CloseRequisitionAction: QueryException', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao encerrar requisicao: ' . $e->getMessage());

            Log::error('CloseRequisitionAction: Erro inesperado', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Recria movimentos RESERVATION apenas quando a requisicao foi reaberta
     * e ainda existe liberacao pendente para recompor.
     */
    private function shouldRecreateReservations(Requisition $requisition): bool
    {
        $netReleasedQuantity = (float) StockMovement::query()
            ->where('source_type', 'requisition')
            ->where('source_id', $requisition->id)
            ->whereIn('type', [
                Type::RESERVATION_RELEASE->value,
                Type::RESERVATION->value,
            ])
            ->get(['type', 'quantity'])
            ->sum(function (StockMovement $movement): float {
                return $movement->type === Type::RESERVATION_RELEASE
                    ? (float) $movement->quantity
                    : -(float) $movement->quantity;
            });

        Log::debug('CloseRequisitionAction: Net released quantity', [
            'metodo'         => __METHOD__ . '@' . __LINE__,
            'requisition_id' => $requisition->id,
            'net_released_quantity' => $netReleasedQuantity,
        ]);

        $shouldRecreate = $netReleasedQuantity > 0.0001;

        if (! $shouldRecreate && $requisition->stock_reserved === false) {
            Log::warning('CloseRequisitionAction: Flag indicava reabertura, mas nao ha liberacao pendente; recriacao ignorada', [
                'requisition_id'        => $requisition->id,
                'stock_reserved'        => $requisition->stock_reserved,
                'net_released_quantity' => $netReleasedQuantity,
            ]);
        }

        return $shouldRecreate;
    }

    private function recreateReservations(Requisition $requisition, $items): void
    {
        $stockMovementService = app(StockMovementService::class);
        $productStockService = app(ProductStockService::class);

        foreach ($items as $item) {
            if (! $item->product_id || ! $item->product?->has_stock_control) {
                continue;
            }

            $stock = $productStockService->findByProductId($item->product_id, $requisition->company_id);

            if (! $stock) {
                Log::warning('CloseRequisitionAction: ProductStock nao encontrado ao recriar reserva', [
                    'product_id'     => $item->product_id,
                    'item_id'        => $item->id,
                    'requisition_id' => $requisition->id,
                ]);

                continue;
            }

            $movement = $stockMovementService->create([
                'product_stock_id' => $stock->id,
                'product_id'       => $item->product_id,
                'company_id'       => $requisition->company_id,
                'type'             => Type::RESERVATION->value,
                'quantity'         => (float) $item->quantity,
                'unit_price'       => (float) ($item->unit_price ?? 0),
                'reason'           => 'Reserva recriada por re-encerramento - requisicao #' . $requisition->number,
                'source_type'      => 'requisition',
                'source_id'        => $requisition->id,
            ], $this->userId);

            if (! $movement) {
                throw new \Exception(
                    'Falha ao recriar reserva de estoque para produto #' . $item->product_id
                    . ': ' . $stockMovementService->getMessage()
                );
            }

            Log::info('CloseRequisitionAction: Reserva recriada apos reabertura', [
                'product_id'     => $item->product_id,
                'quantity'       => $item->quantity,
                'movement_id'    => $movement->id,
                'requisition_id' => $requisition->id,
            ]);
        }
    }

    private function hasSufficientStockForClose(
        Requisition $requisition,
        RequisitionItem $item,
        ProductStockService $productStockService,
    ): bool {
        $stock = $productStockService->findByProductId($item->product_id, $requisition->company_id);

        if (! $stock || $stock->allow_negative) {
            return true;
        }

        $requestedQuantity = (float) $item->quantity;
        $availableQuantity = (float) $stock->quantity_available;
        $reservedForItem = $this->resolveItemReservedQuantity($requisition, $item);
        $effectiveAvailable = $availableQuantity + $reservedForItem;

        Log::debug('CloseRequisitionAction: Effective availability resolved for close', [
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

    private function resolveItemReservedQuantity(Requisition $requisition, RequisitionItem $item): float
    {
        $itemReservedQuantity = (float) StockMovement::query()
            ->where('source_type', 'requisition_item')
            ->where('source_id', $item->id)
            ->whereIn('type', [
                Type::RESERVATION->value,
                Type::RESERVATION_RELEASE->value,
            ])
            ->get(['type', 'quantity'])
            ->sum(function (StockMovement $movement): float {
                return $movement->type === Type::RESERVATION
                    ? (float) $movement->quantity
                    : -(float) $movement->quantity;
            });

        if ($itemReservedQuantity > 0.0001) {
            return min($itemReservedQuantity, (float) $item->quantity);
        }

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
                return $movement->type === Type::RESERVATION
                    ? (float) $movement->quantity
                    : -(float) $movement->quantity;
            });

        return min(max($reservedQuantity, 0), (float) $item->quantity);
    }
}
