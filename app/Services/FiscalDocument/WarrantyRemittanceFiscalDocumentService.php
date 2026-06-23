<?php

namespace App\Services\FiscalDocument;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\WarrantyClaim\Type as WarrantyClaimType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
use App\Models\RequisitionItem;
use App\Models\WarrantyClaim;
use App\Services\Fiscal\Actions\PersistFiscalSnapshotAction;
use App\Services\Fiscal\Actions\ResolveFiscalContextAction;
use App\Services\FiscalDocumentItem\FiscalDocumentItemResolverService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WarrantyRemittanceFiscalDocumentService
{
    use HandlesServiceResponse;

    public function generateFromWarrantyClaim(WarrantyClaim $claim, int $userId): ?FiscalDocument
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($claim, $userId): ?FiscalDocument {
                $claim->loadMissing([
                    'product.tax',
                    'supplier',
                    'originFiscalDocument.items',
                    'originRequisition.items.product',
                ]);

                if (! $this->validateWarrantyClaim($claim)) {
                    return null;
                }

                $originFiscalItem = $this->resolveOriginFiscalItem($claim);
                $originRequisitionItem = $this->resolveOriginRequisitionItem($claim);
                $itemSource = app(FiscalDocumentItemResolverService::class)->resolveForProduct((int) $claim->product_id);

                if ($originFiscalItem === null && $originRequisitionItem === null && $itemSource === null) {
                    $this->setError('Não foi possível resolver os dados fiscais do produto para a remessa em garantia.');

                    return null;
                }

                $documentService = app(FiscalDocumentService::class);
                $document = $documentService->create($this->buildDocumentData($claim), $userId);

                if ($documentService->hasError() || ! $document instanceof FiscalDocument) {
                    $this->setError(
                        $documentService->getMessage(),
                        $documentService->getErrors(),
                        422,
                        $documentService->getErrorCode(),
                    );

                    return null;
                }

                $itemPayload = $this->buildItemData($claim, $document->id, $originFiscalItem, $originRequisitionItem, $itemSource);

                $itemService = app(\App\Services\FiscalDocumentItem\FiscalDocumentItemService::class);
                $createdItems = $itemService->createMany([$itemPayload], $userId);

                if ($itemService->hasError() || $createdItems === null) {
                    $this->setError(
                        $itemService->getMessage(),
                        $itemService->getErrors(),
                        422,
                        $itemService->getErrorCode(),
                    );

                    return null;
                }

                $document->load('items');

                $resolver = app(ResolveFiscalContextAction::class);
                $decisions = $resolver->execute($document, $document->items->all());

                if ($resolver->hasError() || $decisions === []) {
                    $this->setError($resolver->getMessage() ?: 'Não foi possível resolver as regras fiscais da remessa em garantia.');

                    return null;
                }

                $snapshotPersister = app(PersistFiscalSnapshotAction::class);

                if (! $snapshotPersister->execute($document, $decisions)) {
                    $this->setError($snapshotPersister->getMessage() ?: 'Não foi possível persistir o snapshot fiscal da remessa em garantia.');

                    return null;
                }

                if ($originFiscalItem instanceof FiscalDocumentItem) {
                    $returnItem = $document->items->first();

                    if ($returnItem instanceof FiscalDocumentItem) {
                        FiscalDocumentItemOrigin::query()->create([
                            'origin_fiscal_document_id' => $originFiscalItem->fiscal_document_id,
                            'origin_fiscal_document_item_id' => $originFiscalItem->id,
                            'return_fiscal_document_id' => $document->id,
                            'return_fiscal_document_item_id' => $returnItem->id,
                            'linked_quantity' => (float) $claim->quantity,
                            'linked_value' => (float) $returnItem->total_price,
                            'origin_document_key' => $claim->originFiscalDocument?->document_key,
                            'metadata' => [
                                'warranty_claim_id' => $claim->id,
                                'generation_mode' => 'warranty_remittance',
                            ],
                        ]);
                    }
                }

                $claim->forceFill([
                    'remittance_fiscal_document_id' => $document->id,
                    'updated_by' => $userId,
                ])->save();

                $this->setSuccess('NF-e de remessa em garantia gerada com sucesso.');

                Log::info('WarrantyRemittanceFiscalDocumentService: remessa em garantia gerada', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'warranty_claim_id' => $claim->id,
                    'fiscal_document_id' => $document->id,
                    'origin_fiscal_document_item_id' => $originFiscalItem?->id,
                    'user_id' => $userId,
                ]);

                return $document;
            });
        } catch (\Throwable $e) {
            Log::error('WarrantyRemittanceFiscalDocumentService: exceção ao gerar remessa em garantia', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'warranty_claim_id' => $claim->id,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->setError('Erro ao gerar NF-e de remessa em garantia.');

            return null;
        }
    }

    private function validateWarrantyClaim(WarrantyClaim $claim): bool
    {
        if ($claim->type !== WarrantyClaimType::PRODUCT_SUPPLIER) {
            $this->setError('A remessa em garantia só pode ser gerada para garantias de peça com fornecedor.');

            return false;
        }

        if (! $claim->supplier_id) {
            $this->setError('A garantia não possui fornecedor vinculado.');

            return false;
        }

        if (! $claim->product_id) {
            $this->setError('A garantia não possui produto vinculado.');

            return false;
        }

        if ($claim->hasGeneratedRemittanceFiscalDocument()) {
            $this->setError('Já existe uma NF-e de remessa em garantia vinculada a esta garantia.');

            return false;
        }

        return true;
    }

    private function buildDocumentData(WarrantyClaim $claim): array
    {
        $originDocument = $claim->originFiscalDocument;

        return [
            'customer_id' => $claim->supplier_id,
            'company_id' => $claim->company_id,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'operation_nature' => OperationNature::REMESSA_GARANTIA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => (bool) ($originDocument?->is_final_consumer ?? false),
            'buyer_presence_indicator' => $originDocument?->buyer_presence_indicator?->value ?? BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => data_get($originDocument?->freight_data, 'modalidade_frete', FreightModality::SEM_FRETE->value),
            ],
            'tax_data' => [
                'reference' => [
                    'type' => 'warranty_remittance',
                    'warranty_claim_id' => $claim->id,
                    'origin_service_order_id' => $claim->origin_service_order_id,
                    'origin_requisition_id' => $claim->origin_requisition_id,
                    'origin_invoice_id' => $claim->origin_invoice_id,
                    'origin_fiscal_document_id' => $claim->origin_fiscal_document_id,
                ],
            ],
            'additional_taxpayer_information' => $this->buildOriginReferenceText($claim),
        ];
    }

    private function buildItemData(
        WarrantyClaim $claim,
        int $documentId,
        ?FiscalDocumentItem $originFiscalItem,
        ?RequisitionItem $originRequisitionItem,
        mixed $itemSource,
    ): array {
        $unitPrice = $this->resolveUnitPrice($originFiscalItem, $originRequisitionItem, $itemSource);
        $quantity = (float) $claim->quantity;
        $productOrigin = $originFiscalItem?->product_origin
            ?: ($itemSource?->productOrigin ?? null);
        $ncmCode = $originFiscalItem?->ncm_code
            ?: ($itemSource?->ncmCode ?? null);
        $cestCode = $originFiscalItem?->cest_code
            ?: ($itemSource?->cestCode ?? null);
        $barcode = $originFiscalItem?->barcode
            ?: ($itemSource?->barcode ?? null);
        $description = $originFiscalItem?->description
            ?: ($itemSource?->name ?? $claim->product?->name);
        $productCode = $originFiscalItem?->product_code
            ?: ($itemSource?->productCode ?? $claim->product?->product_code);
        $unitOfMeasure = $originFiscalItem?->unit_of_measure
            ?: ($itemSource?->unit ?? $claim->product?->unit?->value);
        $taxData = is_array($originFiscalItem?->tax_data) ? $originFiscalItem->tax_data : null;
        $fiscalSnapshot = is_array($originFiscalItem?->fiscal_snapshot) ? $originFiscalItem->fiscal_snapshot : [];

        $fiscalSnapshot['warranty_remittance_origin'] = [
            'warranty_claim_id' => $claim->id,
            'origin_fiscal_document_id' => $claim->origin_fiscal_document_id,
            'origin_requisition_id' => $claim->origin_requisition_id,
        ];

        return [
            'fiscal_document_id' => $documentId,
            'product_id' => $claim->product_id,
            'product_code' => $productCode,
            'description' => $description,
            'item_number' => 1,
            'product_origin' => $productOrigin,
            'ncm_code' => $ncmCode,
            'cest_code' => $cestCode,
            'barcode' => $barcode,
            'cfop_code' => $originFiscalItem?->cfop_code,
            'quantity' => $quantity,
            'unit_of_measure' => $unitOfMeasure,
            'taxable_unit' => $originFiscalItem?->taxable_unit ?: $unitOfMeasure,
            'taxable_quantity' => $quantity,
            'taxable_unit_price' => $unitPrice,
            'unit_price' => $unitPrice,
            'total_price' => round($unitPrice * $quantity, 2),
            'discount_amount' => null,
            'freight_amount' => null,
            'insurance_amount' => null,
            'other_expenses_amount' => null,
            'included_in_total' => true,
            'tax_data' => $taxData,
            'fiscal_snapshot' => $fiscalSnapshot !== [] ? $fiscalSnapshot : null,
            'additional_information' => 'Gerado a partir da garantia #'.$claim->number,
        ];
    }

    private function resolveOriginFiscalItem(WarrantyClaim $claim): ?FiscalDocumentItem
    {
        return $claim->originFiscalDocument?->items
            ->first(fn (FiscalDocumentItem $item): bool => (int) $item->product_id === (int) $claim->product_id);
    }

    private function resolveOriginRequisitionItem(WarrantyClaim $claim): ?RequisitionItem
    {
        return $claim->originRequisition?->items
            ->first(fn (RequisitionItem $item): bool => (int) $item->product_id === (int) $claim->product_id);
    }

    private function resolveUnitPrice(?FiscalDocumentItem $originFiscalItem, ?RequisitionItem $originRequisitionItem, mixed $itemSource): float
    {
        if ($originFiscalItem instanceof FiscalDocumentItem) {
            return (float) $originFiscalItem->unit_price;
        }

        if ($originRequisitionItem instanceof RequisitionItem) {
            return (float) $originRequisitionItem->unit_price;
        }

        return (float) ($itemSource?->price ?? 0);
    }

    private function buildOriginReferenceText(WarrantyClaim $claim): string
    {
        $parts = array_filter([
            'Garantia: #'.$claim->number,
            $claim->originServiceOrder?->number ? 'OS origem: #'.$claim->originServiceOrder->number : null,
            $claim->originRequisition?->number ? 'Req. origem: #'.$claim->originRequisition->number : null,
            $claim->originInvoice?->invoice_number ? 'Fatura origem: '.$claim->originInvoice->invoice_number : null,
            $claim->originFiscalDocument?->document_number ? 'NF origem: '.$claim->originFiscalDocument->document_number : null,
            $claim->supplier_protocol ? 'Protocolo fornecedor: '.$claim->supplier_protocol : null,
        ]);

        return implode(' | ', $parts);
    }
}
