<?php

namespace App\Services\Invoice;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Enum\Invoice\Status;
use App\Models\FiscalDocument;
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use App\Models\ServiceOrderItem;
use App\Services\FiscalDocument\FiscalDocumentService;
use App\Services\FiscalDocumentItem\FiscalDocumentItemService;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use App\Services\Invoice\Actions\CreateInvoiceAction;
use App\Services\Invoice\Actions\DeleteInvoiceAction;
use App\Services\Invoice\Actions\PrintInvoicePdfAction;
use App\Services\Invoice\Actions\UpdateInvoiceAction;
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
                    'company.fiscalProfile',
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

                if ($documentType === DocumentModel::NFSE->value) {
                    $items[] = $this->buildSingleNfseItem($invoice, $fiscalDocument);
                } else {
                    foreach ($invoice->requisitions as $requisition) {
                        foreach ($requisition->items as $reqItem) {
                            $product = $reqItem->product;
                            $productTax = $product?->tax;

                            $items[] = [
                                'fiscal_document_id' => $fiscalDocument->id,
                                'product_id'         => $reqItem->product_id,
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

        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Erro de validação ao gerar documento fiscal a partir da fatura.';

            $this->setError($message, $e->errors(), 422);

            Log::warning($message, [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'invoice_id' => $invoice->id,
                'errors'     => $e->errors(),
                'user_id'    => $userId,
            ]);

            return null;
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

    private function buildSingleNfseItem(Invoice $invoice, FiscalDocument $fiscalDocument): array
    {
        $profile = $invoice->company?->fiscalProfile;

        $sourceItems = $invoice->serviceOrders
            ->flatMap(function ($serviceOrder) {
                return $serviceOrder->items->map(fn (ServiceOrderItem $item): array => [
                    'service_order' => $serviceOrder,
                    'item' => $item,
                    'service' => $item->service,
                ]);
            })
            ->values();

        if ($sourceItems->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'A fatura não possui itens de serviço para gerar a NFS-e.',
            ]);
        }

        $municipalTaxCode = $this->resolveSingleNfseValue(
            $sourceItems,
            fn (array $row): ?string => $row['service']?->municipal_tax_code,
            $profile?->default_municipal_tax_code ?? $profile?->default_service_code,
            'código de tributação municipal'
        );

        $nbsCode = $this->resolveSingleNfseValue(
            $sourceItems,
            fn (array $row): ?string => $row['service']?->nbs_code,
            $profile?->default_nbs_code,
            'código NBS'
        );

        $cnaeCode = $this->resolvePreferredNfseValue(
            $sourceItems,
            fn (array $row): ?string => $row['service']?->cnae_code,
            $profile?->service_cnae_code
        );

        $issRate = $this->resolvePreferredNfseValue(
            $sourceItems,
            fn (array $row): float|string|null => $row['service']?->tax_rate,
            $profile?->iss_rate_default
        );

        $issExigibility = $this->resolvePreferredNfseValue(
            $sourceItems,
            fn (array $row): ?string => $row['service']?->iss_exigibility?->value,
            null
        );

        $serviceIds = $sourceItems
            ->map(fn (array $row): ?int => $row['item']->service_id ? (int) $row['item']->service_id : null)
            ->filter()
            ->unique()
            ->values();

        $serviceId = $serviceIds->count() === 1 ? $serviceIds->first() : null;

        $totalValue = round($sourceItems->sum(
            fn (array $row): float => round((float) $row['item']->quantity * (float) $row['item']->unit_price, 2)
        ), 2);

        $orderNumbers = $sourceItems
            ->map(fn (array $row): string => (string) ($row['service_order']->number ?? $row['service_order']->id))
            ->unique()
            ->values();

        $description = $sourceItems->count() > 1
            ? $this->buildCompactNfseDescription($invoice)
            : $this->buildDetailedNfseDescription($sourceItems, $orderNumbers);

        $additionalInformation = mb_substr(
            'OS vinculadas: ' . $orderNumbers->map(fn (string $number): string => '#' . $number)->implode(', '),
            0,
            500
        );

        return [
            'fiscal_document_id' => $fiscalDocument->id,
            'service_id' => $serviceId,
            'description' => $description,
            'additional_information' => $additionalInformation,
            'unit_of_measure' => 'UN',
            'quantity' => 1,
            'unit_price' => $totalValue,
            'total_price' => $totalValue,
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
                            'items' => $rows->map(function (array $row): array {
                                $item = $row['item'];
                                $service = $row['service'];

                                return [
                                    'service_order_item_id' => (int) $item->id,
                                    'service_id' => $item->service_id ? (int) $item->service_id : null,
                                    'service_name' => $service?->name ?? $item->observations,
                                    'quantity' => (float) $item->quantity,
                                    'unit_price' => (float) $item->unit_price,
                                    'total_price' => round((float) $item->quantity * (float) $item->unit_price, 2),
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

    private function resolveSingleNfseValue(
        Collection $sourceItems,
        callable $resolver,
        mixed $defaultValue,
        string $fieldLabel
    ): mixed {
        $values = $sourceItems
            ->map(function (array $row) use ($resolver) {
                $value = $resolver($row);

                if (is_string($value)) {
                    $value = trim($value);
                }

                return $value;
            })
            ->filter(fn ($value): bool => $value !== null && $value !== '')
            ->unique()
            ->values();

        if ($values->count() > 1) {
            throw ValidationException::withMessages([
                'items' => "A fatura agrupa serviços com {$fieldLabel} diferentes. Gere NFS-e separadas ou padronize a classificação fiscal.",
            ]);
        }

        return $values->first() ?? $defaultValue;
    }

    private function resolvePreferredNfseValue(
        Collection $sourceItems,
        callable $resolver,
        mixed $defaultValue
    ): mixed {
        $value = $sourceItems
            ->map(function (array $row) use ($resolver) {
                $resolved = $resolver($row);

                if (is_string($resolved)) {
                    $resolved = trim($resolved);
                }

                return $resolved;
            })
            ->first(fn ($resolved): bool => $resolved !== null && $resolved !== '');

        return $value ?? $defaultValue;
    }

    private function buildCompactNfseDescription(Invoice $invoice): string
    {
        $parts = $invoice->serviceOrders
            ->map(function ($serviceOrder): string {
                $orderNumber = $serviceOrder->number ?? $serviceOrder->id;
                $totalAmount = number_format((float) $serviceOrder->total_amount, 2, ',', '.');

                return "OS {$orderNumber} - Total R$ {$totalAmount}";
            })
            ->values();

        return mb_substr($parts->implode(' | '), 0, 2000);
    }

    private function buildDetailedNfseDescription(Collection $sourceItems, Collection $orderNumbers): string
    {
        $serviceSummaries = $sourceItems
            ->map(function (array $row): string {
                $serviceOrder = $row['service_order'];
                $item = $row['item'];
                $service = $row['service'];

                $serviceName = trim((string) ($service?->name ?? $item->observations ?? 'Serviço'));
                $orderNumber = $serviceOrder->number ?? $serviceOrder->id;
                $quantity = $this->formatDecimal((float) $item->quantity, 3);
                $lineTotal = number_format(
                    round((float) $item->quantity * (float) $item->unit_price, 2),
                    2,
                    ',',
                    '.'
                );

                return "OS {$orderNumber}: {$serviceName} (qtd {$quantity}, total R$ {$lineTotal})";
            })
            ->values();

        $descriptionParts = [];
        $descriptionParts[] = 'Referente às ordens de serviço ' . $orderNumbers->map(
            fn (string $number): string => '#' . $number
        )->implode(', ') . '.';
        $descriptionParts[] = 'Serviços faturados: ' . $serviceSummaries->implode(' | ');

        return mb_substr(trim(implode(' ', $descriptionParts)), 0, 2000);
    }

    private function formatDecimal(float $value, int $precision = 2): string
    {
        $formatted = number_format($value, $precision, ',', '.');

        return rtrim(rtrim($formatted, '0'), ',');
    }
}
