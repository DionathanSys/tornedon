<?php

namespace App\Services\ServiceOrder\Actions;

use App\Exceptions\DomainValidationException;
use App\Models\ServiceOrder;
use App\Services\FiscalDocument\NfeDocumentService;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceServiceOrderAction
{
    use HandlesActionResponse;

    public function __construct(
        private int $userId
    ) {}

    /**
     * Orquestra o faturamento completo da OS:
     *  1. Cria Invoice + FiscalDocument + FiscalDocumentItems
     *  2. Muda status → INVOICED
     *  3. Despacha job de emissão da NF-e
     */
    public function execute(ServiceOrder $order): ?ServiceOrder
    {
        try {
            Log::debug('InvoiceServiceOrderAction: Iniciando faturamento', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'user_id'          => $this->userId,
            ]);

            return DB::transaction(function () use ($order): ServiceOrder {
                // 1. Cria Invoice → FiscalDocument → FiscalDocumentItems
                $createAction = new CreateInvoiceDocumentForServiceOrderAction($this->userId);
                $fiscalDocument = $createAction->execute($order);

                if ($createAction->hasError() || ! $fiscalDocument) {
                    $this->setError(
                        $createAction->getMessage() ?: 'Falha ao criar cadeia fiscal da OS.',
                        $createAction->getErrors(),
                    );
                    throw new \RuntimeException($this->getMessage());
                }

                // 2. Transição de estado: CLOSED → INVOICED
                $order->state()->invoice($order, $this->userId);
                $order->refresh();

                // 3. Despacha job de emissão da NF-e (assíncrono)
                $nfeService = app(NfeDocumentService::class);
                $nfeService->emitir($fiscalDocument, $this->userId);

                if ($nfeService->hasError()) {
                    // Não aborta: NF-e pode ser reenviada manualmente.
                    Log::warning('InvoiceServiceOrderAction: NF-e não enfileirada', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'service_order_id'   => $order->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'nfe_error'          => $nfeService->getMessage(),
                    ]);
                }

                Log::info('InvoiceServiceOrderAction: OS faturada com sucesso', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'service_order_id'   => $order->id,
                    'invoice_id'         => $fiscalDocument->invoice_id,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                $this->setSuccess();
                return $order;
            });

        } catch (DomainValidationException $e) {
            $this->setError('Transição inválida', $e->errors);

            Log::warning('InvoiceServiceOrderAction: Transição inválida', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'errors'           => $e->errors,
            ]);

            return null;
        } catch (QueryException $e) {
            $this->setError('Erro ao faturar OS no banco de dados');

            Log::error('InvoiceServiceOrderAction: QueryException', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'exception'        => $e->getMessage(),
            ]);

            return null;
        } catch (\Exception $e) {
            if (! $this->hasError()) {
                $this->setError('Erro ao faturar OS: ' . $e->getMessage());
            }

            Log::error('InvoiceServiceOrderAction: Erro inesperado', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'service_order_id' => $order->id,
                'exception'        => $e->getMessage(),
                'trace'            => $e->getTraceAsString(),
            ]);

            return null;
        }
    }
}
