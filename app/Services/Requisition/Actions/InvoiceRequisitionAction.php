<?php

namespace App\Services\Requisition\Actions;

use App\Enum\StockMovement\Type;
use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Services\ProductStock\ProductStockService;
use App\Services\StockMovement\StockMovementService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    public function execute(Requisition $requisition): ?Requisition
    {
        try {
            DB::transaction(function () use ($requisition) {
                Log::debug('InvoiceRequisitionAction: Faturando requisição', [
                    'metodo'         => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                    'user_id'        => $this->userId,
                ]);

                // 1. Transição de estado
                $requisition->state()->invoice($requisition, $this->userId);

                // 2. Libera reserva e gera saída física por item
                $this->processStockExits($requisition);

                $requisition->refresh();
            });

            $this->setSuccess();
            return $requisition;
        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('InvoiceRequisitionAction: Transição inválida', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'errors'         => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar requisição no banco de dados');

            Log::error('InvoiceRequisitionAction: QueryException', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
            ]);

            return null;
        } catch (\RuntimeException $e) {
            $this->setError($e->getMessage());

            Log::error('InvoiceRequisitionAction: RuntimeException', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'exception'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Para cada item ainda não consumido:
     *  1. Cria movimentação RESERVATION_RELEASE (devolve a reserva)
     *  2. Cria movimentação EXIT (saída física do estoque)
     *  3. Marca o item como consumido
     * Ao final, marca a requisição como stock_consumed.
     */
    private function processStockExits(Requisition $requisition): void
    {
        $stockMovementService = app(StockMovementService::class);
        $productStockService  = app(ProductStockService::class);

        $items = $requisition->items()
            ->where('stock_consumed', false)
            ->get();

        if ($items->isEmpty()) {
            Log::info('InvoiceRequisitionAction: Nenhum item pendente de saída de estoque', [
                'requisition_id' => $requisition->id,
            ]);
            return;
        }

        foreach ($items as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = $item->product;

            if (! $product || ! $product->has_stock_control) {
                // Produto sem controle de estoque: marca apenas como consumido
                $item->update([
                    'stock_consumed'    => true,
                    'stock_consumed_at' => now(),
                ]);
                continue;
            }

            $productStock = $productStockService->findByProductId(
                $item->product_id,
                $requisition->company_id
            );

            if (! $productStock) {
                Log::warning('InvoiceRequisitionAction: ProductStock não encontrado', [
                    'product_id'     => $item->product_id,
                    'requisition_id' => $requisition->id,
                    'item_id'        => $item->id,
                ]);
                throw new \RuntimeException(
                    'Estoque não encontrado para o produto #' . $item->product_id
                );
            }

            $baseData = [
                'product_stock_id' => $productStock->id,
                'product_id'       => $item->product_id,
                'company_id'       => $requisition->company_id,
                'quantity'         => (float) $item->quantity,
                'unit_price'       => (float) ($item->unit_price ?? 0),
                'source_type'      => 'requisition',
                'source_id'        => $requisition->id,
                'observations'     => $item->observations,
            ];

            // 2a. Libera a reserva criada no fechamento
            $release = $stockMovementService->create(array_merge($baseData, [
                'type'   => Type::RESERVATION_RELEASE->value,
                'reason' => 'Liberação de reserva — faturamento requisição #' . $requisition->number,
            ]), $this->userId);

            if (! $release) {
                throw new \RuntimeException(
                    'Falha ao liberar reserva para produto #' . $item->product_id
                    . ': ' . $stockMovementService->getMessage()
                );
            }

            // 2b. Saída física do estoque
            $exit = $stockMovementService->create(array_merge($baseData, [
                'type'   => Type::EXIT->value,
                'reason' => 'Saída por faturamento — requisição #' . $requisition->number,
            ]), $this->userId);

            if (! $exit) {
                throw new \RuntimeException(
                    'Falha ao criar saída de estoque para produto #' . $item->product_id
                    . ': ' . $stockMovementService->getMessage()
                );
            }

            // 2c. Marca o item como consumido
            $item->update([
                'stock_consumed'    => true,
                'stock_consumed_at' => now(),
            ]);

            Log::info('InvoiceRequisitionAction: Saída de estoque processada', [
                'product_id'     => $item->product_id,
                'quantity'       => $item->quantity,
                'release_id'     => $release->id,
                'exit_id'        => $exit->id,
                'requisition_id' => $requisition->id,
            ]);
        }

        // 3. Marca a requisição como consumida
        $requisition->update(['stock_consumed' => true]);
    }
}
