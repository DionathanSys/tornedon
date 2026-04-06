<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Models\StockMovement;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
            Log::debug('CloseRequisitionAction: Encerrando requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $this->userId,
                'stock_reserved' => $requisition->stock_reserved,
            ]);

            return DB::transaction(function () use ($requisition) {
                $productStockService = app(ProductStockService::class);
                $items = $requisition->items()->where('stock_consumed', false)->with('product')->get();

                // 1. Valida saldo disponível para todos os itens antes de prosseguir
                foreach ($items as $item) {
                    if (! $item->product_id || ! $item->product?->has_stock_control) {
                        continue;
                    }

                    if (! $productStockService->hasNetAvailableStock($item->product_id, $requisition->company_id, (float) $item->quantity)) {
                        $this->setError(sprintf(
                            'Saldo insuficiente para "%s". Verifique o estoque antes de encerrar.',
                            $item->product->name ?? "Produto #{$item->product_id}"
                        ));
                        return null;
                    }
                }

                // 2. Se stock_reserved === false a requisição foi reaberta e as reservas
                //    foram liberadas — é necessário recriá-las agora.
                //    Quando stock_reserved === null (primeiro fechamento) os listeners
                //    já criaram as reservas ao adicionar cada item (sem duplicar).
                if ($requisition->stock_reserved === false && ! $this->hasActiveReservations($requisition, $items)) {
                    $this->recreateReservations($requisition, $items);
                }

                // 3. Transição de estado (open → closed)
                $requisition->state()->close($requisition, $this->userId);

                // 4. Marca que as reservas estão ativas no estoque
                $requisition->update(['stock_reserved' => true]);

                $requisition->refresh();

                $this->setSuccess();
                return $requisition;
            });

        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('CloseRequisitionAction: Transição inválida', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'errors'         => $e->errors,
            ]);

            return null;

        } catch (QueryException $e) {
            $this->setError('Erro ao encerrar requisição no banco de dados');

            Log::error('CloseRequisitionAction: QueryException', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
            ]);

            return null;

        } catch (\Exception $e) {
            $this->setError('Erro ao encerrar requisição: ' . $e->getMessage());

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
     * Recria movimentos RESERVATION para todos os itens não consumidos.
     * Chamado apenas quando stock_reserved === false (ciclo: fechado → reaberto → fechado).
     */
    private function recreateReservations(Requisition $requisition, $items): void
    {
        $stockMovementService = app(StockMovementService::class);
        $productStockService  = app(ProductStockService::class);

        foreach ($items as $item) {
            if (! $item->product_id || ! $item->product?->has_stock_control) {
                continue;
            }

            $stock = $productStockService->findByProductId($item->product_id, $requisition->company_id);

            if (! $stock) {
                Log::warning('CloseRequisitionAction: ProductStock não encontrado ao recriar reserva', [
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
                'reason'           => 'Reserva recriada por re-encerramento — requisição #' . $requisition->number,
                'source_type'      => 'requisition',
                'source_id'        => $requisition->id,
            ], $this->userId);

            if (! $movement) {
                throw new \Exception(
                    'Falha ao recriar reserva de estoque para produto #' . $item->product_id
                    . ': ' . $stockMovementService->getMessage()
                );
            }

            Log::info('CloseRequisitionAction: Reserva recriada após reabertura', [
                'product_id'     => $item->product_id,
                'quantity'       => $item->quantity,
                'movement_id'    => $movement->id,
                'requisition_id' => $requisition->id,
            ]);
        }
    }

    /**
     * Evita recriar reservas quando a requisição já possui saldo reservado ativo.
     */
    private function hasActiveReservations(Requisition $requisition, Collection $items): bool
    {
        $itemIds = $items->pluck('id')->filter()->values();

        if ($itemIds->isEmpty()) {
            return false;
        }

        $netReservedByProduct = StockMovement::query()
            ->where(function ($query) use ($requisition, $itemIds) {
                $query
                    ->where(function ($q) use ($requisition) {
                        $q->where('source_type', 'requisition')
                            ->where('source_id', $requisition->id);
                    })
                    ->orWhere(function ($q) use ($itemIds) {
                        $q->where('source_type', 'requisition_item')
                            ->whereIn('source_id', $itemIds);
                    });
            })
            ->whereIn('type', [
                Type::RESERVATION->value,
                Type::RESERVATION_RELEASE->value,
            ])
            ->get(['product_id', 'type', 'quantity'])
            ->groupBy('product_id')
            ->map(function (Collection $movements): float {
                return (float) $movements->sum(function (StockMovement $movement): float {
                    return $movement->type === Type::RESERVATION
                        ? (float) $movement->quantity
                        : -(float) $movement->quantity;
                });
            });

        $hasActiveReservations = $netReservedByProduct->contains(
            fn (float $quantity): bool => $quantity > 0.0001
        );

        if ($hasActiveReservations) {
            Log::warning('CloseRequisitionAction: Reserva já estava ativa; recriação ignorada para evitar duplicidade', [
                'requisition_id'          => $requisition->id,
                'net_reserved_by_product' => $netReservedByProduct->all(),
            ]);
        }

        return $hasActiveReservations;
    }
}
