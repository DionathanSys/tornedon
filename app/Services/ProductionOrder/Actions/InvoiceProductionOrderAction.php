<?php

namespace App\Services\ProductionOrder\Actions;

use App\Domain\Exceptions\ProductionOrder\InvalidStateTransitionException;
use App\Models\ProductionOrder;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceProductionOrderAction
{
    use HandlesActionResponse;

    private ?\App\Models\FiscalDocument $fiscalDocumentForNfe = null;

    public function __construct(
        private int $userId,
    ) {}

    /**
     * Orquestra o faturamento completo da OP:
     *  1. Cria Invoice + FiscalDocument + FiscalDocumentItems
     *  2. Muda status → INVOICED
     *  3. Despacha job de emissão da NF-e
     */
    public function execute(ProductionOrder $productionOrder): ?ProductionOrder
    {
        try {
            Log::debug('InvoiceProductionOrderAction: Iniciando faturamento', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'user_id'             => $this->userId,
            ]);

            DB::transaction(function () use ($productionOrder) {
                // 1. Cria Invoice → FiscalDocument → FiscalDocumentItems
                $createAction   = new CreateInvoiceDocumentForProductionOrderAction($this->userId);
                $fiscalDocument = $createAction->execute($productionOrder);

                if ($createAction->hasError() || ! $fiscalDocument) {
                    $this->setError(
                        $createAction->getMessage() ?: 'Falha ao criar cadeia fiscal da OP.',
                        $createAction->getErrors(),
                    );
                    throw new \RuntimeException($this->getMessage());
                }

                // 2. Transição de estado: COMPLETED → INVOICED
                $productionOrder->state()->invoice();
                $productionOrder->refresh();

                $this->fiscalDocumentForNfe = $fiscalDocument;
            });

            // 3. Despacha NF-e fora da transaction
            if ($this->fiscalDocumentForNfe) {
                $nfeService = app(NfeDocumentService::class);
                $nfeService->emitir($this->fiscalDocumentForNfe, $this->userId);

                if ($nfeService->hasError()) {
                    Log::warning('InvoiceProductionOrderAction: NF-e não enfileirada', [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'production_order_id' => $productionOrder->id,
                        'fiscal_document_id'  => $this->fiscalDocumentForNfe->id,
                        'nfe_error'           => $nfeService->getMessage(),
                    ]);
                }
            }

            Log::info('InvoiceProductionOrderAction: OP faturada com sucesso', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
            ]);

            $this->setSuccess();
            return $productionOrder;

        } catch (InvalidStateTransitionException $e) {
            $this->setError($e->getMessage());

            Log::warning('InvoiceProductionOrderAction: Transição inválida', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'exception'           => $e->getMessage(),
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar OP no banco de dados');

            Log::error('InvoiceProductionOrderAction: QueryException', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'exception'           => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar OP: ' . $e->getMessage());
            }

            Log::error('InvoiceProductionOrderAction: Erro inesperado', [
                'metodo'              => __METHOD__ . '@' . __LINE__,
                'production_order_id' => $productionOrder->id,
                'exception'           => $e->getMessage(),
                'trace'               => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
