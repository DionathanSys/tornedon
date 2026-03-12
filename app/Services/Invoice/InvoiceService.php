<?php

namespace App\Services\Invoice;

use App\Enum\AccountReceivable\Status as AccountReceivableStatus;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status;
use App\Models\AccountReceivable;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Services\FiscalDocument\FiscalDocumentService;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use App\Services\Invoice\Actions\CreateInvoiceAction;
use App\Services\Invoice\Actions\DeleteInvoiceAction;
use App\Services\Invoice\Actions\PrintInvoicePdfAction;
use App\Services\Invoice\Actions\UpdateInvoiceAction;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    use HandlesServiceResponse;

    /* ==============================
     |  Operações de Escrita
     |==============================*/

    public function create(array $data, int $createdBy): ?Invoice
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($data, $createdBy) {
                if (empty($data['invoice_number']) && isset($data['company_id'])) {
                    $data['invoice_number'] = $this->generateNumber($data['company_id']);
                }

                $data['status'] = $data['status'] ?? Status::PENDING->value;

                $action = new CreateInvoiceAction($createdBy);
                $invoice = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'         => __METHOD__ . '@' . __LINE__,
                        'message'        => $this->getMessage(),
                        'error_code'     => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors'         => $action->getErrors(),
                        'data'           => $data,
                        'user_id'        => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Fatura criada com sucesso');

                Log::info('Fatura criada com sucesso via service', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                    'number'     => $invoice->invoice_number,
                ]);

                return $invoice;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $createdBy,
            ]);

            return null;
        }
    }

    public function update(Invoice $invoice, array $data, int $updatedBy): ?Invoice
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice, $data, $updatedBy) {
                $action = new UpdateInvoiceAction($updatedBy, $invoice);
                $updated = $action->execute($data);

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $invoice->id,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $action->getErrors(),
                        'data'       => $data,
                        'user_id'    => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Fatura atualizada com sucesso');

                Log::info('Fatura atualizada com sucesso via service', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
                'user_id'    => $updatedBy,
            ]);

            return null;
        }
    }

    public function delete(Invoice $invoice): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice) {
                $action = new DeleteInvoiceAction($invoice);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $invoice->id,
                        'message'    => $action->getMessage(),
                        'error_code' => $action->getErrorCode(),
                        'errors'     => $action->getErrors(),
                    ]);

                    return false;
                }

                $this->setSuccess('Fatura excluída com sucesso');

                Log::info('Fatura excluída com sucesso via service', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /* ==============================
     |  Documento Fiscal
     |==============================*/

    /**
     * Cria um documento fiscal (NF-e) a partir da fatura, importando os itens das
     * requisições e ordens de serviço vinculadas.
     */
    public function createFiscalDocument(Invoice $invoice, array $fiscalData, int $userId): ?FiscalDocument
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice, $fiscalData, $userId) {
                $documentType = ($fiscalData['document_type'] ?? DocumentModel::NFE->value);

                $invoice->loadMissing([
                    'requisitions.items.product.tax',
                    'serviceOrders.items.service',
                    'company',
                    'customer',
                ]);

                // ------------------------------------------------------------------
                // 1. Criar cabeçalho do Documento Fiscal via FiscalDocumentService
                // ------------------------------------------------------------------
                $documentData = array_merge($fiscalData, [
                    'customer_id'   => $invoice->customer_id,
                    'company_id'    => $invoice->company_id,
                    'invoice_id'    => $invoice->id,
                    'status'        => FiscalDocumentStatus::PENDING->value,
                    'document_type' => $documentType,
                    'issued_at'     => $fiscalData['issued_at'] ?? now()->toDateString(),
                    'movement_at'   => $fiscalData['movement_at'] ?? now()->toDateString(),
                ]);

                Log::debug('Iniciando criação do documento fiscal a partir da fatura via InvoiceService', [
                    'metodo'     => __METHOD__ . '@' . __LINE__,
                    'invoice_id' => $invoice->id,
                    'data'       => $documentData,
                    'user_id'    => $userId,
                ]);

                $fiscalDocumentService = app(FiscalDocumentService::class);
                $fiscalDocument = $fiscalDocumentService->create($documentData, $userId);

                if ($fiscalDocumentService->hasError() || $fiscalDocument === null) {
                    $this->setError(
                        $fiscalDocumentService->getMessage(),
                        $fiscalDocumentService->getErrors(),
                        422,
                        $fiscalDocumentService->getErrorCode()
                    );
                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $invoice->id,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $fiscalDocumentService->getErrors(),
                        'data'       => $documentData,
                        'user_id'    => $userId,
                    ]);
                    return null;
                }

                Log::info('Cabeçalho do documento fiscal criado via InvoiceService', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'invoice_id'         => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                // ------------------------------------------------------------------
                // 2. Montar itens e criar via bulk insert
                // ------------------------------------------------------------------

                Log::info('Iniciando criação dos itens do documento fiscal via InvoiceService', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'invoice_id'         => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'total_requisitions' => $invoice->requisitions->count(),
                ]);

                $items = [];
                $itemNumber = 1;

                if ($documentType === DocumentModel::NFSE->value) {
                    foreach ($invoice->serviceOrders as $serviceOrder) {
                        foreach ($serviceOrder->items as $serviceOrderItem) {
                            $service = $serviceOrderItem->service;

                            $items[] = [
                                'fiscal_document_id' => $fiscalDocument->id,
                                'service_id'         => $serviceOrderItem->service_id,
                                'item_number'        => $itemNumber,
                                'description'        => $service?->name ?? $serviceOrderItem->observations,
                                'unit_of_measure'    => $service?->unit_of_measure ?? 'UN',
                                'quantity'           => (float) $serviceOrderItem->quantity,
                                'unit_price'         => (float) $serviceOrderItem->unit_price,
                                'total_price'        => round((float) $serviceOrderItem->quantity * (float) $serviceOrderItem->unit_price, 2),
                                'service_code'       => $service?->service_code,
                                'nbs_code'           => $service?->nbs_code,
                                'iss_exigibility'    => $service?->iss_exigibility?->value,
                                'iss_rate'           => $service?->tax_rate !== null ? (float) $service->tax_rate : null,
                                'included_in_total'  => true,
                            ];

                            $itemNumber++;
                        }
                    }
                } else {
                    foreach ($invoice->requisitions as $requisition) {
                        foreach ($requisition->items as $reqItem) {
                            $product = $reqItem->product;
                            $productTax = $product?->tax;

                            $items[] = [
                                'fiscal_document_id' => $fiscalDocument->id,
                                'product_id'         => $reqItem->product_id,
                                'item_number'        => $itemNumber,
                                'product_code'       => $product?->product_code,
                                'product_origin'     => $productTax?->product_origin?->value,
                                'barcode'            => $product?->barcode,
                                'description'        => $product?->name,
                                'ncm_code'           => $productTax?->ncm_code,
                                'cest_code'          => $productTax?->cest_code,
                                'unit_of_measure'    => $reqItem->unit_of_measure ?? $product?->unit?->value,
                                'quantity'           => (float) $reqItem->quantity,
                                'unit_price'         => (float) $reqItem->unit_price,
                                'total_price'        => round((float) $reqItem->quantity * (float) $reqItem->unit_price, 2),
                                'included_in_total'  => true,
                            ];

                            $itemNumber++;
                        }
                    }
                }

                if (empty($items)) {
                    $this->setError(
                        $documentType === DocumentModel::NFSE->value
                            ? 'A fatura não possui itens de serviço para gerar a NFS-e.'
                            : 'A fatura não possui itens de produto para gerar a NF-e.'
                    );

                    Log::warning('InvoiceService: Não foi possível montar itens para o documento fiscal', [
                        'metodo'              => __METHOD__ . '@' . __LINE__,
                        'invoice_id'          => $invoice->id,
                        'fiscal_document_id'  => $fiscalDocument->id,
                        'document_type'       => $documentType,
                        'service_orders'      => $invoice->serviceOrders->count(),
                        'requisitions'        => $invoice->requisitions->count(),
                    ]);

                    return null;
                }

                Log::info('Itens montados para criação do documento fiscal via InvoiceService', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'invoice_id'         => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'total_items'        => count($items),
                ]);

                $fiscalDocumentItemService = app(FiscalDocumentItemService::class);
                $createdItems = $fiscalDocumentItemService->createMany($items, $userId);

                if ($fiscalDocumentItemService->hasError() || $createdItems === null) {
                    $this->setError(
                        $fiscalDocumentItemService->getMessage(),
                        $fiscalDocumentItemService->getErrors(),
                        422,
                        $fiscalDocumentItemService->getErrorCode()
                    );
                    Log::error($this->getMessage(), [
                        'metodo'     => __METHOD__ . '@' . __LINE__,
                        'invoice_id' => $invoice->id,
                        'message'    => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors'     => $fiscalDocumentItemService->getErrors(),
                        'data'       => $items,
                        'user_id'    => $userId,
                    ]);
                    return null;
                }

                // ------------------------------------------------------------------
                // 3. Resolver decisão fiscal e persistir snapshot nos itens
                // ------------------------------------------------------------------
                $step3StartedAt = microtime(true);

                Log::info('InvoiceService: Iniciando etapa 3 (resolver contexto fiscal e persistir snapshot)', [
                    'metodo'               => __METHOD__ . '@' . __LINE__,
                    'invoice_id'           => $invoice->id,
                    'fiscal_document_id'   => $fiscalDocument->id,
                    'total_items_payload'  => count($items),
                    'total_items_criados'  => is_countable($createdItems) ? count($createdItems) : null,
                ]);

                $resolveAction = app(ResolveFiscalContextAction::class);
                $decisions = $resolveAction->execute($fiscalDocument, $items);

                if ($resolveAction->hasError()) {
                    Log::error('InvoiceService: Falha ao resolver contexto fiscal', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'invoice_id'         => $invoice->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'error_code'         => $resolveAction->getErrorCode(),
                        'message'            => $resolveAction->getMessage(),
                        'errors'             => $resolveAction->getErrors(),
                        'decisions_count'    => count($decisions),
                    ]);
                } else {
                    Log::info('InvoiceService: Contexto fiscal resolvido', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'invoice_id'         => $invoice->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'decisions_count'    => count($decisions),
                    ]);
                }

                $snapshotPersisted = false;

                if (!empty($decisions)) {
                    $snapshotAction = new PersistFiscalSnapshotAction();
                    $snapshotPersisted = $snapshotAction->execute($fiscalDocument, $decisions);

                    if (!$snapshotPersisted || $snapshotAction->hasError()) {
                        Log::error('InvoiceService: Falha ao persistir snapshot fiscal nos itens', [
                            'metodo'             => __METHOD__ . '@' . __LINE__,
                            'invoice_id'         => $invoice->id,
                            'fiscal_document_id' => $fiscalDocument->id,
                            'error_code'         => $snapshotAction->getErrorCode(),
                            'message'            => $snapshotAction->getMessage(),
                            'errors'             => $snapshotAction->getErrors(),
                            'decisions_count'    => count($decisions),
                        ]);
                    } else {
                        Log::info('InvoiceService: Snapshot fiscal persistido com sucesso', [
                            'metodo'             => __METHOD__ . '@' . __LINE__,
                            'invoice_id'         => $invoice->id,
                            'fiscal_document_id' => $fiscalDocument->id,
                            'decisions_count'    => count($decisions),
                        ]);
                    }
                } else {
                    Log::warning('InvoiceService: Nenhuma decisão fiscal retornada; etapa de snapshot foi ignorada', [
                        'metodo'             => __METHOD__ . '@' . __LINE__,
                        'invoice_id'         => $invoice->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'total_items_payload'=> count($items),
                    ]);
                }

                $step3ElapsedMs = (int) round((microtime(true) - $step3StartedAt) * 1000);

                Log::info('InvoiceService: Etapa 3 finalizada', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'invoice_id'         => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'decisions_count'    => count($decisions),
                    'snapshot_persisted' => $snapshotPersisted,
                    'elapsed_ms'         => $step3ElapsedMs,
                ]);

                // ------------------------------------------------------------------
                // 4. Sincronizar financeiro da fatura
                // ------------------------------------------------------------------
                $this->syncInvoiceStatusAfterFiscalDocumentGeneration($invoice, $userId);
                $this->ensureAccountReceivableLink($invoice, $fiscalDocument);

                $this->setSuccess('Documento fiscal criado com sucesso a partir da fatura.');

                Log::info('Documento fiscal criado a partir da fatura via InvoiceService', [
                    'metodo'             => __METHOD__ . '@' . __LINE__,
                    'invoice_id'         => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'total_items'        => count($items),
                    'decisions_count'    => count($decisions),
                    'snapshot_persisted' => $snapshotPersisted,
                    'step3_elapsed_ms'   => $step3ElapsedMs,
                ]);

                return $fiscalDocument;
            });

        } catch (\Exception $e) {
            $this->setError('Erro ao gerar documento fiscal a partir da fatura');

            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'message'    => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'invoice_id' => $invoice->id,
                'user_id'    => $userId,
            ]);

            return null;
        }
    }

    private function syncInvoiceStatusAfterFiscalDocumentGeneration(Invoice $invoice, int $userId): void
    {
        $invoice->update([
            'status'       => Status::CONFIRMED->value,
            'pending'      => false,
            'confirmed'    => true,
            'confirmed_at' => now(),
            'updated_by'   => $userId,
        ]);

        Log::info('InvoiceService: Status da fatura atualizado após geração do documento fiscal', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'invoice_id' => $invoice->id,
            'status'     => Status::CONFIRMED->value,
        ]);
    }

    private function ensureAccountReceivableLink(Invoice $invoice, FiscalDocument $fiscalDocument): void
    {
        $invoice->loadMissing(['accountReceivables']);

        $documentNumber = $fiscalDocument->document_number
            ?? $fiscalDocument->document_key
            ?? $invoice->invoice_number;

        $dueDate = $invoice->invoice_date ?? now()->toDateString();
        $dueAmount = round((float) $invoice->total_amount, 2);

        if ($invoice->accountReceivables->isNotEmpty()) {
            $invoice->accountReceivables
                ->whereNull('document_number')
                ->each(function (AccountReceivable $accountReceivable) use ($documentNumber): void {
                    $accountReceivable->update([
                        'document_number' => $documentNumber,
                    ]);
                });

            Log::info('InvoiceService: Conta(s) a receber já vinculada(s) à fatura', [
                'metodo'                     => __METHOD__ . '@' . __LINE__,
                'invoice_id'                 => $invoice->id,
                'total_account_receivables'  => $invoice->accountReceivables->count(),
            ]);

            return;
        }

        // Mantém consistência financeira quando a fatura ainda não possui parcela gerada.
        AccountReceivable::create([
            'customer_id'      => $invoice->customer_id,
            'company_id'       => $invoice->company_id,
            'invoice_id'       => $invoice->id,
            'sequence_number'  => '01',
            'status'           => AccountReceivableStatus::PENDING->value,
            'due_date'         => $dueDate,
            // Compatibilidade com schema legado que pode não aceitar nulos.
            'paid_date'        => $dueDate,
            'due_amount'       => $dueAmount,
            'paid_amount'      => 0,
            'document_number'  => $documentNumber,
            'description'      => 'Gerada automaticamente pela emissão de documento fiscal da fatura ' . $invoice->invoice_number,
            'paid'             => false,
        ]);

        Log::info('InvoiceService: Conta a receber criada automaticamente após geração do documento fiscal', [
            'metodo'             => __METHOD__ . '@' . __LINE__,
            'invoice_id'         => $invoice->id,
            'fiscal_document_id' => $fiscalDocument->id,
            'due_amount'         => $dueAmount,
        ]);
    }

    /**
     * Gera o PDF da fatura em base64.
     */
    public function pdf(Invoice $invoice, int $userId): ?string
    {
        $this->resetResponse();

        try {
            $action = new PrintInvoicePdfAction();
            $pdf    = $action->execute($invoice);

            if ($pdf === null || $action->hasError()) {
                $this->setError($action->getMessage());
                return null;
            }

            $this->setSuccess('PDF da fatura gerado.');

            Log::info('InvoiceService: PDF gerado com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'user_id'    => $userId,
            ]);

            return $pdf;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da fatura: ' . $e->getMessage());

            Log::error('InvoiceService::pdf', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'user_id'    => $userId,
                'exception'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Gera o preview do PDF da fatura.
     *
     * @return array{pdf:string}|null
     */
    public function preview(Invoice $invoice, int $userId): ?array
    {
        $this->resetResponse();

        try {
            $pdf = $this->pdf($invoice, $userId);

            if ($pdf === null) {
                return null;
            }

            return ['pdf' => $pdf];
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar preview da fatura: ' . $e->getMessage());

            Log::error('InvoiceService::preview', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'user_id'    => $userId,
                'exception'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* ==============================
     |  Métodos Auxiliares
     |==============================*/

    /**
     * Gera o próximo número de fatura para a empresa (lock pessimista).
     */
    public function generateNumber(int $companyId): string
    {
        $sequence = InvoiceSequence::lockForUpdate()
            ->firstOrCreate(
                ['company_id' => $companyId],
                ['last_number' => 0]
            );

        $sequence->increment('last_number');

        return str_pad($sequence->last_number, 6, '0', STR_PAD_LEFT);
    }
}
