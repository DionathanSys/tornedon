<?php

namespace App\Services\Requisition\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\Requisition;
use App\Services\Audit\AuditRecorder;
use App\Services\Requisition\RequisitionStockWorkflow;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CloseRequisitionAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId,
        private ?RequisitionStockWorkflow $stockWorkflow = null,
    ) {}

    public function execute(Requisition $requisition): ?Requisition
    {
        try {
            if (! $requisition->items()->exists()) {
                $this->setError('Não é possível encerrar requisição sem itens.');

                Log::warning('CloseRequisitionAction: requisição sem itens', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'requisition_id' => $requisition->id,
                ]);

                return null;
            }

            $audit = app(AuditRecorder::class);
            $before = $audit->snapshot($requisition);

            Log::debug('CloseRequisitionAction: Encerrando requisicao', [
                'metodo'         => __METHOD__ . '@' . __LINE__,
                'requisition_id' => $requisition->id,
                'user_id'        => $this->userId,
                'stock_reserved' => $requisition->stock_reserved,
            ]);

            return DB::transaction(function () use ($requisition, $audit, $before) {
                $stockWorkflow = $this->stockWorkflow ?? app(RequisitionStockWorkflow::class);
                $items = $stockWorkflow->pendingItems($requisition, withProduct: true);

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

                    if (! $stockWorkflow->hasSufficientStockForClose($requisition, $item)) {
                        $this->setError(sprintf(
                            'Saldo insuficiente para "%s". Verifique o estoque antes de encerrar.',
                            $item->product->name ?? "Produto #{$item->product_id}"
                        ));

                        return null;
                    }
                }

                if (! $stockWorkflow->recreateReservationsIfNeeded($requisition, $this->userId)) {
                    $this->setError($stockWorkflow->getMessage(), $stockWorkflow->getErrors(),$stockWorkflow->getErrorCode());

                    return null;
                }

                if ($items->isNotEmpty()) {
                    Log::debug('CloseRequisitionAction: Recreating reservations', [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'requisition_id' => $requisition->id,
                        'user_id'        => $this->userId,
                    ]);
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
}
