<?php

namespace App\Services\Requisition\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Services\ProductStock\ProductStockService;
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
            Log::debug('CloseRequisitionAction: Encerrando requisição', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $this->userId,
            ]);

            return DB::transaction(function () use ($requisition) {
                // 1. Valida saldo disponível para todos os itens antes de prosseguir
                $productStockService = app(ProductStockService::class);
                $items = $requisition->items()->where('stock_consumed', false)->with('product')->get();

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

                // 2. Transição de estado (open → closed)
                $requisition->state()->close($requisition, $this->userId);

                // 3. Reserva o estoque gerando movimentações de RESERVATION
                $consumeAction = new ConsumeRequisitionStockAction($this->userId);
                $consumed = $consumeAction->execute($requisition);

                if (! $consumed) {
                    throw new \Exception(
                        'Falha ao reservar estoque: ' . $consumeAction->getMessage()
                    );
                }

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
}
