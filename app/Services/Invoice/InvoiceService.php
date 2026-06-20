<?php

namespace App\Services\Invoice;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfseDescriptionMode;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status;
use App\Models\CompanyPreference;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Models\RequisitionItem;
use App\Models\Service as ServiceModel;
use App\Models\ServiceOrderItem;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use App\Services\FiscalDocument\FiscalDocumentService;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use App\Services\Invoice\Actions\CreateInvoiceAction;
use App\Services\Invoice\Actions\DeleteInvoiceAction;
use App\Services\Invoice\Actions\GenerateInvoiceAccountReceivablesAction;
use App\Services\Invoice\Actions\PrintInvoicePdfAction;
use App\Services\Invoice\Actions\ReturnInvoiceToPendingAction;
use App\Services\Invoice\Actions\UpdateInvoiceAction;
use App\Services\Product\ProductUnitConversionService;
use App\Support\Fiscal\FiscalItemAmounts;
use App\Support\Fiscal\NfsePrintSettings;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'action_message' => $action->getMessage(),
                        'errors' => $action->getErrors(),
                        'data' => $data,
                        'user_id' => $createdBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Fatura criada com sucesso');

                Log::info('Fatura criada com sucesso via service', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'number' => $invoice->invoice_number,
                ]);

                return $invoice;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao criar fatura');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $createdBy,
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
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'data' => $data,
                        'user_id' => $updatedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Fatura atualizada com sucesso');

                Log::info('Fatura atualizada com sucesso via service', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                ]);

                return $updated;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar fatura');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $updatedBy,
            ]);

            return null;
        }
    }

    public function delete(Invoice $invoice, int $deletedBy): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice, $deletedBy) {
                $action = new DeleteInvoiceAction($invoice, $deletedBy);
                $result = $action->execute();

                if ($action->hasError()) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'message' => $action->getMessage(),
                        'error_code' => $action->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'user_id' => $deletedBy,
                    ]);

                    return false;
                }

                $this->setSuccess('Fatura excluída com sucesso');

                Log::info('Fatura excluída com sucesso via service', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'user_id' => $deletedBy,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao excluir fatura');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $deletedBy,
            ]);

            return false;
        }
    }

    public function confirm(Invoice $invoice, array $data, int $confirmedBy): ?array
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice, $data, $confirmedBy) {
                $action = new Actions\ConfirmInvoiceAction($invoice, $confirmedBy);
                $result = $action->execute($data);

                if ($action->hasError() || $result === null) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    Log::error($this->getMessage(), [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'message' => $action->getMessage(),
                        'error_code' => $action->getErrorCode(),
                        'errors' => $action->getErrors(),
                        'data' => $data,
                        'user_id' => $confirmedBy,
                    ]);

                    return null;
                }

                $this->setSuccess('Fatura confirmada com sucesso', $result);

                Log::info('Fatura confirmada com sucesso via service - Invoice ID: '.$invoice->id, [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'documents_count' => $result['documents_count'] ?? 0,
                    'account_receivables_count' => $result['account_receivables_count'] ?? 0,
                    'user_id' => $confirmedBy,
                ]);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao confirmar fatura');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $confirmedBy,
            ]);

            return null;
        }
    }

    public function returnToPending(Invoice $invoice, int $userId): bool
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice, $userId) {
                $action = new ReturnInvoiceToPendingAction($invoice, $userId);
                $result = $action->execute();

                if ($action->hasError() || ! $result) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    return false;
                }

                $this->setSuccess('Fatura retornada para pendente com sucesso.');

                return true;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao retornar fatura para pendente');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $userId,
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, mixed>|null
     */
    public function generateAccountReceivables(Invoice $invoice, array $data, int $userId): ?array
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($invoice, $data, $userId) {
                $action = new GenerateInvoiceAccountReceivablesAction($invoice, $userId);
                $result = $action->execute($data);

                if ($action->hasError() || $result === null) {
                    $this->setError(
                        $action->getMessage(),
                        $action->getErrors(),
                        422,
                        $action->getErrorCode()
                    );

                    return null;
                }

                $this->setSuccess('Contas a receber geradas com sucesso.', $result);

                return $result;
            });
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar contas a receber da fatura');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
                'user_id' => $userId,
            ]);

            return null;
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
                    'serviceOrders.equipment',
                    'company.fiscalProfile',
                    'customer',
                ]);

                // ------------------------------------------------------------------
                // 1. Criar cabeçalho do Documento Fiscal via FiscalDocumentService
                // ------------------------------------------------------------------
                $documentData = array_merge($fiscalData, [
                    'customer_id' => $invoice->customer_id,
                    'company_id' => $invoice->company_id,
                    'invoice_id' => $invoice->id,
                    'status' => FiscalDocumentStatus::PENDING->value,
                    'document_type' => $documentType,
                    'issued_at' => $fiscalData['issued_at'] ?? now()->toDateString(),
                    'movement_at' => $fiscalData['movement_at'] ?? now()->toDateString(),
                ]);

                Log::debug('Iniciando criação do documento fiscal a partir da fatura via InvoiceService', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'data' => $documentData,
                    'user_id' => $userId,
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
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $fiscalDocumentService->getErrors(),
                        'data' => $documentData,
                        'user_id' => $userId,
                    ]);

                    return null;
                }

                Log::info('Cabeçalho do documento fiscal criado via InvoiceService', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                ]);

                // ------------------------------------------------------------------
                // 2. Montar itens e criar via bulk insert
                // ------------------------------------------------------------------

                Log::info('Iniciando criação dos itens do documento fiscal via InvoiceService', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'total_requisitions' => $invoice->requisitions->count(),
                ]);

                $items = [];

                if ($documentType === DocumentModel::NFSE->value) {
                    $items[] = $this->buildSingleNfseItem($invoice, $fiscalDocument, $fiscalData);
                } else {
                    foreach ($invoice->requisitions as $requisition) {
                        foreach ($requisition->items as $reqItem) {
                            $items[] = $this->buildProductFiscalItemFromRequisition($fiscalDocument->id, $reqItem);
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
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'document_type' => $documentType,
                        'service_orders' => $invoice->serviceOrders->count(),
                        'requisitions' => $invoice->requisitions->count(),
                    ]);

                    return null;
                }

                Log::info('Itens montados para criação do documento fiscal via InvoiceService', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'total_items' => count($items),
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
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'message' => $this->getMessage(),
                        'error_code' => $this->getErrorCode(),
                        'errors' => $fiscalDocumentItemService->getErrors(),
                        'data' => $items,
                        'user_id' => $userId,
                    ]);

                    return null;
                }

                // ------------------------------------------------------------------
                // 3. Resolver decisão fiscal e persistir snapshot nos itens
                // ------------------------------------------------------------------
                $step3StartedAt = microtime(true);

                Log::info('InvoiceService: Iniciando etapa 3 (resolver contexto fiscal e persistir snapshot)', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'total_items_payload' => count($items),
                    'total_items_criados' => is_countable($createdItems) ? count($createdItems) : null,
                ]);

                $resolveAction = app(ResolveFiscalContextAction::class);
                $decisions = $resolveAction->execute($fiscalDocument, $items);

                if ($resolveAction->hasError()) {
                    Log::error('InvoiceService: Falha ao resolver contexto fiscal', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'error_code' => $resolveAction->getErrorCode(),
                        'message' => $resolveAction->getMessage(),
                        'errors' => $resolveAction->getErrors(),
                        'decisions_count' => count($decisions),
                    ]);
                } else {
                    Log::info('InvoiceService: Contexto fiscal resolvido', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'decisions_count' => count($decisions),
                    ]);
                }

                $snapshotPersisted = false;

                if (! empty($decisions)) {
                    $snapshotAction = new PersistFiscalSnapshotAction;
                    $snapshotPersisted = $snapshotAction->execute($fiscalDocument, $decisions);

                    if (! $snapshotPersisted || $snapshotAction->hasError()) {
                        Log::error('InvoiceService: Falha ao persistir snapshot fiscal nos itens', [
                            'metodo' => __METHOD__.'@'.__LINE__,
                            'invoice_id' => $invoice->id,
                            'fiscal_document_id' => $fiscalDocument->id,
                            'error_code' => $snapshotAction->getErrorCode(),
                            'message' => $snapshotAction->getMessage(),
                            'errors' => $snapshotAction->getErrors(),
                            'decisions_count' => count($decisions),
                        ]);
                    } else {
                        Log::info('InvoiceService: Snapshot fiscal persistido com sucesso', [
                            'metodo' => __METHOD__.'@'.__LINE__,
                            'invoice_id' => $invoice->id,
                            'fiscal_document_id' => $fiscalDocument->id,
                            'decisions_count' => count($decisions),
                        ]);
                    }
                } else {
                    Log::warning('InvoiceService: Nenhuma decisão fiscal retornada; etapa de snapshot foi ignorada', [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'invoice_id' => $invoice->id,
                        'fiscal_document_id' => $fiscalDocument->id,
                        'total_items_payload' => count($items),
                    ]);
                }

                $step3ElapsedMs = (int) round((microtime(true) - $step3StartedAt) * 1000);

                Log::info('InvoiceService: Etapa 3 finalizada', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'decisions_count' => count($decisions),
                    'snapshot_persisted' => $snapshotPersisted,
                    'elapsed_ms' => $step3ElapsedMs,
                ]);

                // ------------------------------------------------------------------
                // 4. Sincronizar financeiro da fatura
                // ------------------------------------------------------------------
                $this->syncInvoiceStatusAfterFiscalDocumentGeneration($invoice, $userId);

                $this->setSuccess('Documento fiscal criado com sucesso a partir da fatura.');

                Log::info('Documento fiscal criado a partir da fatura via InvoiceService', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'invoice_id' => $invoice->id,
                    'fiscal_document_id' => $fiscalDocument->id,
                    'total_items' => count($items),
                    'decisions_count' => count($decisions),
                    'snapshot_persisted' => $snapshotPersisted,
                    'step3_elapsed_ms' => $step3ElapsedMs,
                ]);

                return $fiscalDocument;
            });

        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Erro de validação ao gerar documento fiscal a partir da fatura.';

            $this->setError($message, $e->errors(), 422);

            Log::warning($message, [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'errors' => $e->errors(),
                'user_id' => $userId,
            ]);

            return null;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar documento fiscal a partir da fatura');

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__.'@'.__LINE__,
                'error_code' => $this->getErrorCode(),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
            ]);

            return null;
        }
    }

    private function buildProductFiscalItemFromRequisition(int $fiscalDocumentId, RequisitionItem $reqItem): array
    {
        $product = $reqItem->product;
        $productTax = $product?->tax;
        $unitOfMeasure = $reqItem->unit_of_measure ?? $product?->unit?->value;
        $quantity = (float) $reqItem->quantity;
        $unitPrice = (float) $reqItem->unit_price;
        $totalPrice = FiscalItemAmounts::grossTotal($reqItem->quantity, $reqItem->unit_price);

        $item = [
            'fiscal_document_id' => $fiscalDocumentId,
            'product_id' => $reqItem->product_id,
            'product_code' => $product?->product_code,
            'product_origin' => $productTax?->product_origin?->value,
            'barcode' => $product?->barcode,
            'description' => $product?->name,
            'ncm_code' => $productTax?->ncm_code,
            'cest_code' => $productTax?->cest_code,
            'unit_of_measure' => $unitOfMeasure,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'discount_amount' => round((float) ($reqItem->discount_amount ?? 0), 2),
            'included_in_total' => true,
        ];

        if ($product && $unitOfMeasure) {
            $baseConversion = app(ProductUnitConversionService::class)
                ->convertToBase($product, (string) $unitOfMeasure, $quantity);

            if ($baseConversion->operationalUnit !== $baseConversion->baseUnit) {
                $item['taxable_unit'] = $baseConversion->baseUnit;
                $item['taxable_quantity'] = round($baseConversion->baseQuantity, 4);
                $item['taxable_unit_price'] = $baseConversion->baseQuantity > 0
                    ? round($totalPrice / $baseConversion->baseQuantity, 4)
                    : $unitPrice;
            }
        }

        return $item;
    }

    private function syncInvoiceStatusAfterFiscalDocumentGeneration(Invoice $invoice, int $userId): void
    {
        $invoice->update([
            'status' => Status::CONFIRMED->value,
            'pending' => false,
            'confirmed' => true,
            'confirmed_at' => now(),
            'confirmed_by' => $userId,
            'updated_by' => $userId,
        ]);

        Log::info('InvoiceService: Status da fatura atualizado após geração do documento fiscal', [
            'metodo' => __METHOD__.'@'.__LINE__,
            'invoice_id' => $invoice->id,
            'status' => Status::CONFIRMED->value,
        ]);
    }

    /**
     * Gera o PDF da fatura em base64.
     */
    public function pdf(Invoice $invoice, int $userId): ?string
    {
        $this->resetResponse();

        try {
            $action = app(PrintInvoicePdfAction::class);
            $pdf = $action->execute($invoice);

            if ($pdf === null || $action->hasError()) {
                $this->setError($action->getMessage());

                return null;
            }

            $this->setSuccess('PDF da fatura gerado.');

            Log::info('InvoiceService: PDF gerado com sucesso', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
            ]);

            return $pdf;
        } catch (\Exception $e) {
            $this->setError('Erro ao gerar PDF da fatura: '.$e->getMessage());

            Log::error('InvoiceService::pdf', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
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
            $this->setError('Erro ao gerar preview da fatura: '.$e->getMessage());

            Log::error('InvoiceService::preview', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'invoice_id' => $invoice->id,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
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

    private function buildSingleNfseItem(Invoice $invoice, FiscalDocument $fiscalDocument, array $fiscalData = []): array
    {
        $profile = $invoice->company?->fiscalProfile;

        $sourceItems = $this->getNfseSourceItems($invoice);

        if ($sourceItems->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'A fatura não possui itens de serviço para gerar a NFS-e.',
            ]);
        }

        $selectedService = $this->resolveNfseServiceChoice(
            $invoice,
            isset($fiscalData['nfse_service_id']) ? (int) $fiscalData['nfse_service_id'] : null
        );

        $municipalTaxCode = trim((string) ($selectedService?->municipal_tax_code ?? ''))
            ?: $profile?->default_municipal_tax_code
            ?: $profile?->default_service_code;

        $nbsCode = trim((string) ($selectedService?->nbs_code ?? ''))
            ?: $profile?->default_nbs_code;

        $cnaeCode = trim((string) ($selectedService?->cnae_code ?? ''))
            ?: $profile?->service_cnae_code;

        $issRate = $selectedService?->tax_rate ?? $profile?->iss_rate_default;
        $issExigibility = $selectedService?->iss_exigibility?->value;
        $serviceId = $selectedService?->id;

        $totalGrossValue = round($sourceItems->sum(
            fn (array $row): float => FiscalItemAmounts::grossTotal($row['item']->quantity, $row['item']->unit_price)
        ), 2);
        $totalDiscount = round($sourceItems->sum(
            fn (array $row): float => (float) ($row['item']->discount_amount ?? 0)
        ), 2);
        $allowUnconditionalDiscount = (bool) ($profile?->allow_unconditional_discount_nfse ?? false);

        $totalValue = $allowUnconditionalDiscount
            ? $totalGrossValue
            : max(0, round($totalGrossValue - $totalDiscount, 2));

        $discountAmount = $allowUnconditionalDiscount ? $totalDiscount : 0.0;

        $orderNumbers = $sourceItems
            ->map(fn (array $row): string => (string) ($row['service_order']->number ?? $row['service_order']->id))
            ->unique()
            ->values();

        $configuredDescription = $this->buildConfiguredNfseText(
            $invoice,
            $sourceItems,
            $selectedService,
            'description'
        );

        $configuredAdditionalInformation = $this->buildConfiguredNfseText(
            $invoice,
            $sourceItems,
            $selectedService,
            'additional_information'
        );

        $description = trim((string) ($fiscalData['nfse_item_description'] ?? ''));

        if ($description === '') {
            $description = $configuredDescription;
        }

        if ($description === '') {
            $description = trim((string) ($selectedService?->name ?? ''));
        }

        if ($description === '') {
            $description = $this->buildNfseServiceNamesDescription($sourceItems);
        }

        if ($description === '') {
            $description = $this->buildNfseOrderNumbersDescription($orderNumbers);
        }

        $description = mb_substr($description, 0, 2000);
        $additionalInformation = trim((string) ($fiscalData['nfse_additional_information'] ?? ''));

        if ($additionalInformation === '') {
            $additionalInformation = $configuredAdditionalInformation;
        }

        if ($additionalInformation === '') {
            $additionalInformation = $this->buildNfseOrderNumbersDescription($orderNumbers);
        }

        $additionalInformation = mb_substr($additionalInformation, 0, 2000);

        return [
            'fiscal_document_id' => $fiscalDocument->id,
            'service_id' => $serviceId,
            'description' => $description,
            'additional_information' => $additionalInformation,
            'unit_of_measure' => 'UN',
            'quantity' => 1,
            'unit_price' => $totalValue,
            'total_price' => $totalValue,
            'discount_amount' => $discountAmount,
            'municipal_tax_code' => $municipalTaxCode,
            'nbs_code' => $nbsCode,
            'cnae_code' => $cnaeCode,
            'iss_exigibility' => $issExigibility,
            'iss_rate' => $issRate !== null ? (float) $issRate : null,
            'included_in_total' => true,
            'fiscal_snapshot' => [
                'aggregation_mode' => 'single_nfse_item_from_invoice',
                'invoice_id' => $invoice->id,
                'service_order_ids' => $sourceItems
                    ->map(fn (array $row): int => (int) $row['service_order']->id)
                    ->unique()
                    ->values()
                    ->all(),
                'service_orders' => $sourceItems
                    ->groupBy(fn (array $row): int => (int) $row['service_order']->id)
                    ->map(function (Collection $rows): array {
                        $serviceOrder = $rows->first()['service_order'];

                        return [
                            'id' => (int) $serviceOrder->id,
                            'number' => (string) ($serviceOrder->number ?? $serviceOrder->id),
                            'location' => $serviceOrder->location,
                            'customer_observations' => $serviceOrder->customer_observations,
                            'technician_observations' => $serviceOrder->technician_observations,
                            'items_received' => $serviceOrder->items_received,
                            'additional_info' => $serviceOrder->additional_info,
                            'equipment' => [
                                'id' => $serviceOrder->equipment?->id,
                                'name' => $serviceOrder->equipment?->name,
                                'identifier' => $serviceOrder->equipment?->identifier,
                                'display' => $this->buildEquipmentDisplay($serviceOrder->equipment),
                            ],
                            'items' => $rows->map(function (array $row): array {
                                $item = $row['item'];
                                $service = $row['service'];

                                return [
                                    'service_order_item_id' => (int) $item->id,
                                    'service_id' => $item->service_id ? (int) $item->service_id : null,
                                    'service_name' => $service?->name ?? $item->observations,
                                    'quantity' => (float) $item->quantity,
                                    'unit_price' => (float) $item->unit_price,
                                    'total_price' => FiscalItemAmounts::grossTotal($item->quantity, $item->unit_price),
                                    'discount_amount' => round((float) ($item->discount_amount ?? 0), 2),
                                    'service_code' => $service?->service_code,
                                    'municipal_tax_code' => $service?->municipal_tax_code,
                                    'nbs_code' => $service?->nbs_code,
                                    'cnae_code' => $service?->cnae_code,
                                ];
                            })->values()->all(),
                        ];
                    })
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function getNfseSourceItems(Invoice $invoice): Collection
    {
        $invoice->loadMissing('serviceOrders.items.service', 'serviceOrders.equipment');

        return $invoice->serviceOrders
            ->flatMap(function ($serviceOrder) {
                return $serviceOrder->items->map(fn (ServiceOrderItem $item): array => [
                    'service_order' => $serviceOrder,
                    'item' => $item,
                    'service' => $item->service,
                ]);
            })
            ->values();
    }

    private function resolveNfseServiceChoice(
        Invoice $invoice,
        ?int $selectedServiceId = null,
        bool $requireChoiceWhenMultiple = true
    ): ?ServiceModel {
        $services = $this->getNfseSourceItems($invoice)
            ->map(fn (array $row): mixed => $row['service'])
            ->filter(fn ($service): bool => $service instanceof ServiceModel)
            ->unique(fn (ServiceModel $service): int => (int) $service->id)
            ->values();

        if ($services->isEmpty()) {
            return null;
        }

        if ($selectedServiceId !== null && $selectedServiceId > 0) {
            $selectedService = $services->first(
                fn (ServiceModel $service): bool => (int) $service->id === $selectedServiceId
            );

            if (! $selectedService instanceof ServiceModel) {
                throw ValidationException::withMessages([
                    'nfse_service_id' => 'O serviço selecionado não pertence às ordens de serviço desta fatura.',
                ]);
            }

            return $selectedService;
        }

        if ($services->count() === 1) {
            return $services->first();
        }

        if ($requireChoiceWhenMultiple) {
            throw ValidationException::withMessages([
                'nfse_service_id' => 'A fatura possui mais de um serviço nas ordens de serviço. Selecione qual serviço deve ser usado na descrição do item da NFS-e.',
            ]);
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function getNfseServiceOptions(Invoice $invoice): array
    {
        return $this->getNfseSourceItems($invoice)
            ->mapWithKeys(function (array $row): array {
                $service = $row['service'];

                if (! $service instanceof ServiceModel) {
                    return [];
                }

                return [(int) $service->id => trim((string) $service->name)];
            })
            ->filter(fn (string $name): bool => $name !== '')
            ->sort()
            ->all();
    }

    public function buildNfseItemDescription(
        Invoice $invoice,
        string $mode = NfseDescriptionMode::AUTO->value,
        ?int $selectedServiceId = null
    ): string {
        $sourceItems = $this->getNfseSourceItems($invoice);
        $selectedService = $this->resolveNfseServiceChoice($invoice, $selectedServiceId, requireChoiceWhenMultiple: false);

        $configuredDescription = $this->buildConfiguredNfseText(
            $invoice,
            $sourceItems,
            $selectedService,
            'description'
        );

        if ($configuredDescription !== '') {
            return mb_substr($configuredDescription, 0, 2000);
        }

        if ($selectedService instanceof ServiceModel) {
            return mb_substr(trim((string) $selectedService->name), 0, 2000);
        }

        return mb_substr($this->buildNfseServiceNamesDescription($sourceItems), 0, 2000);
    }

    public function buildNfseItemAdditionalInformation(Invoice $invoice): string
    {
        $sourceItems = $this->getNfseSourceItems($invoice);

        $configuredAdditionalInformation = $this->buildConfiguredNfseText(
            $invoice,
            $sourceItems,
            null,
            'additional_information'
        );

        if ($configuredAdditionalInformation !== '') {
            return mb_substr($configuredAdditionalInformation, 0, 2000);
        }

        $orderNumbers = $sourceItems
            ->map(fn (array $row): string => (string) ($row['service_order']->number ?? $row['service_order']->id))
            ->unique()
            ->values();

        return $this->buildNfseOrderNumbersDescription($orderNumbers);
    }

    private function buildConfiguredNfseText(
        Invoice $invoice,
        Collection $sourceItems,
        ?ServiceModel $selectedService,
        string $field
    ): string {
        if ($sourceItems->isEmpty()) {
            return '';
        }

        $tokens = data_get(
            CompanyPreference::get(NfsePrintSettings::PREFERENCE_KEY, $invoice->company_id, []),
            'documento_fiscal_nfse.'.$field,
            []
        );

        if (! is_array($tokens) || $tokens === []) {
            return '';
        }

        $separator = $field === 'description' ? ' | ' : "\n";

        return mb_substr(
            collect($tokens)
                ->map(fn (mixed $token): string => $this->resolveConfiguredNfseFieldValue(
                    (string) $token,
                    $invoice,
                    $sourceItems,
                    $selectedService,
                    $field
                ))
                ->filter(fn (string $value): bool => $value !== '')
                ->implode($separator),
            0,
            2000
        );
    }

    private function resolveConfiguredNfseFieldValue(
        string $token,
        Invoice $invoice,
        Collection $sourceItems,
        ?ServiceModel $selectedService,
        string $field
    ): string {
        return match ($token) {
            'service_name' => $this->resolveConfiguredServiceName($sourceItems, $selectedService),
            'service_order_number' => $this->resolveConfiguredServiceOrderNumbers($sourceItems),
            'equipment_display' => $this->resolveConfiguredEquipmentDisplays($sourceItems, $field),
            'customer_observations' => $this->resolveConfiguredCustomerObservations($sourceItems, $field),
            'invoice_number' => trim((string) ($invoice->invoice_number ?? '')),
            default => '',
        };
    }

    private function buildEquipmentDisplay(mixed $equipment): string
    {
        if ($equipment === null) {
            return '';
        }

        $identifier = trim((string) ($equipment->identifier ?? ''));
        $name = trim((string) ($equipment->name ?? ''));

        if ($identifier !== '' && $name !== '') {
            return $identifier.' - '.$name;
        }

        return $identifier !== '' ? $identifier : $name;
    }

    private function resolveConfiguredServiceName(Collection $sourceItems, ?ServiceModel $selectedService): string
    {
        if ($selectedService instanceof ServiceModel) {
            return trim((string) $selectedService->name);
        }

        return $sourceItems
            ->map(function (array $row): string {
                $service = $row['service'];
                $item = $row['item'];

                return trim((string) ($service?->name ?? $item->observations ?? ''));
            })
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->implode(', ');
    }

    private function resolveConfiguredServiceOrderNumbers(Collection $sourceItems): string
    {
        return $sourceItems
            ->map(fn (array $row): string => trim((string) ($row['service_order']->number ?? $row['service_order']->id)))
            ->filter(fn (string $number): bool => $number !== '')
            ->unique()
            ->values()
            ->implode(', ');
    }

    private function resolveConfiguredEquipmentDisplays(Collection $sourceItems, string $field): string
    {
        return $sourceItems
            ->map(fn (array $row): string => $this->buildEquipmentDisplay($row['service_order']->equipment))
            ->filter(fn (string $display): bool => $display !== '')
            ->unique()
            ->values()
            ->implode($field === 'description' ? ', ' : "\n");
    }

    private function resolveConfiguredCustomerObservations(Collection $sourceItems, string $field): string
    {
        return $sourceItems
            ->map(fn (array $row): string => trim((string) ($row['service_order']->customer_observations ?? '')))
            ->filter(fn (string $observation): bool => $observation !== '')
            ->unique()
            ->values()
            ->implode($field === 'description' ? ' | ' : "\n");
    }

    private function stringifyServiceOrderAdditionalInfo(mixed $additionalInfo): string
    {
        if (! is_array($additionalInfo)) {
            return '';
        }

        return collect($additionalInfo)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): string {
                $label = trim((string) ($item['label'] ?? $item['campo'] ?? ''));
                $value = trim((string) ($item['value'] ?? $item['texto'] ?? ''));

                if ($label !== '' && $value !== '') {
                    return $label.': '.$value;
                }

                return $value !== '' ? $value : $label;
            })
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");
    }

    private function buildNfseServiceNamesDescription(Collection $sourceItems): string
    {
        return $sourceItems
            ->map(function (array $row): string {
                $service = $row['service'];
                $item = $row['item'];

                return trim((string) ($service?->name ?? $item->observations ?? ''));
            })
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->implode(' | ');
    }

    private function buildNfseOrderNumbersDescription(Collection $orderNumbers): string
    {
        $description = 'OS referenciadas: '.$orderNumbers
            ->map(fn (string $number): string => '#'.$number)
            ->implode(', ');

        return mb_substr(trim($description), 0, 255);
    }
}
