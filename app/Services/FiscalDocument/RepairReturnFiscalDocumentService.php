<?php

namespace App\Services\FiscalDocument;

use App\Domain\DTO\Fiscal\FiscalDecisionDTO;
use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\FiscalDocumentItemOrigin;
use App\Models\RemittanceAsset;
use App\Models\ServiceOrder;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RepairReturnFiscalDocumentService
{
    use HandlesServiceResponse;

    public function generateFromServiceOrder(ServiceOrder $serviceOrder, int $userId): ?FiscalDocument
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($serviceOrder, $userId): ?FiscalDocument {
                $serviceOrder->loadMissing(['customer', 'remittanceAssets.fiscalDocument', 'remittanceAssets.fiscalDocumentItem']);

                $validation = $this->validateServiceOrder($serviceOrder);

                if (! $validation['valid']) {
                    return null;
                }

                /** @var FiscalDocument $originDocument */
                $originDocument = $validation['origin_document'];
                /** @var \Illuminate\Support\Collection<int,RemittanceAsset> $assets */
                $assets = $validation['assets'];

                $fiscalDocumentService = app(FiscalDocumentService::class);
                $returnDocument = $fiscalDocumentService->create(
                    $this->buildReturnDocumentData($serviceOrder, $originDocument),
                    $userId
                );

                if ($fiscalDocumentService->hasError() || ! $returnDocument instanceof FiscalDocument) {
                    $this->setError(
                        $fiscalDocumentService->getMessage(),
                        $fiscalDocumentService->getErrors(),
                        422,
                        $fiscalDocumentService->getErrorCode()
                    );

                    return null;
                }

                $itemPayloads = $assets
                    ->values()
                    ->map(function (RemittanceAsset $asset, int $index) use ($returnDocument, $serviceOrder): array {
                        return $this->buildReturnItemData($asset, $returnDocument->id, $index + 1, $serviceOrder->id);
                    })
                    ->all();

                $itemService = app(\App\Services\FiscalDocumentItem\FiscalDocumentItemService::class);
                $createdItems = $itemService->createMany($itemPayloads, $userId);

                if ($itemService->hasError() || $createdItems === null) {
                    $this->setError(
                        $itemService->getMessage(),
                        $itemService->getErrors(),
                        422,
                        $itemService->getErrorCode()
                    );

                    return null;
                }

                $createdItemsByNumber = collect($createdItems)->keyBy(
                    fn (FiscalDocumentItem $item): int => (int) $item->item_number
                );

                foreach ($assets->values() as $index => $asset) {
                    $originItem = $asset->fiscalDocumentItem;
                    $returnItem = $createdItemsByNumber->get($index + 1);

                    if (! $originItem instanceof FiscalDocumentItem || ! $returnItem instanceof FiscalDocumentItem) {
                        $this->setError('Não foi possível alinhar os itens da nota de retorno gerada.');

                        return null;
                    }

                    FiscalDocumentItemOrigin::query()->create([
                        'origin_fiscal_document_id' => $asset->fiscal_document_id,
                        'origin_fiscal_document_item_id' => $originItem->id,
                        'return_fiscal_document_id' => $returnDocument->id,
                        'return_fiscal_document_item_id' => $returnItem->id,
                        'linked_quantity' => (float) $asset->received_quantity,
                        'linked_value' => (float) $originItem->total_price,
                        'origin_document_key' => $originDocument->document_key,
                        'metadata' => [
                            'service_order_id' => $serviceOrder->id,
                            'remittance_asset_id' => $asset->id,
                            'origin_item_number' => $originItem->item_number,
                            'return_item_number' => $returnItem->item_number,
                            'generation_mode' => 'repair_return_full',
                        ],
                    ]);

                    $asset->forceFill([
                        'returned_quantity' => (float) $asset->received_quantity,
                        'status' => 'returned',
                        'updated_by' => $userId,
                    ])->save();
                }

                $this->setSuccess('Nota de retorno gerada com sucesso.');

                Log::info('RepairReturnFiscalDocumentService: nota de retorno gerada', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'service_order_id' => $serviceOrder->id,
                    'origin_fiscal_document_id' => $originDocument->id,
                    'return_fiscal_document_id' => $returnDocument->id,
                    'items_count' => count($createdItems),
                    'user_id' => $userId,
                ]);

                return $returnDocument;
            });
        } catch (\Throwable $e) {
            $this->setError('Erro ao gerar nota de retorno.');

            Log::error('RepairReturnFiscalDocumentService: exceção ao gerar retorno', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'service_order_id' => $serviceOrder->id,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function validateServiceOrder(ServiceOrder $serviceOrder): array
    {
        $assets = $serviceOrder->remittanceAssets
            ->filter(fn (RemittanceAsset $asset): bool => $asset->fiscalDocumentItem !== null);

        if ($assets->isEmpty()) {
            $this->setError('A ordem de serviço não possui itens de remessa vinculados para gerar retorno.');

            return ['valid' => false];
        }

        if ($serviceOrder->linkedReturnFiscalDocument() instanceof FiscalDocument) {
            $this->setError('Já existe uma nota de retorno vinculada a esta ordem de serviço.');

            return ['valid' => false];
        }

        $originDocumentIds = $assets->pluck('fiscal_document_id')->unique()->values();

        if ($originDocumentIds->count() !== 1) {
            $this->setError('Todos os itens da OS devem pertencer à mesma nota de remessa para gerar o retorno.');

            return ['valid' => false];
        }

        $originDocument = $assets->first()?->fiscalDocument;

        if (! $originDocument instanceof FiscalDocument) {
            $this->setError('Não foi possível localizar a nota de remessa de origem.');

            return ['valid' => false];
        }

        return [
            'valid' => true,
            'origin_document' => $originDocument,
            'assets' => $assets,
        ];
    }

    private function buildReturnDocumentData(ServiceOrder $serviceOrder, FiscalDocument $originDocument): array
    {
        return [
            'customer_id' => $serviceOrder->customer_id,
            'company_id' => $serviceOrder->company_id,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'operation_nature' => OperationNature::RETORNO_CONSERTO->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::NORMAL->value,
            'is_final_consumer' => (bool) ($originDocument->is_final_consumer ?? false),
            'buyer_presence_indicator' => $originDocument->buyer_presence_indicator?->value
                ?? BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => [
                'modalidade_frete' => data_get($originDocument->freight_data, 'modalidade_frete', FreightModality::SEM_FRETE->value),
            ],
            'tax_data' => [
                'reference' => [
                    'type' => 'repair_return',
                    'service_order_id' => $serviceOrder->id,
                    'fiscal_document_id' => $originDocument->id,
                    'document_number' => $originDocument->document_number,
                    'document_series' => $originDocument->document_series,
                    'document_key' => $originDocument->document_key,
                    'issued_at' => $originDocument->issued_at?->toDateString(),
                ],
            ],
            'additional_taxpayer_information' => $this->buildOriginReferenceText($serviceOrder, $originDocument),
        ];
    }

    private function buildReturnItemData(RemittanceAsset $asset, int $returnDocumentId, int $itemNumber, int $serviceOrderId): array
    {
        $originItem = $asset->fiscalDocumentItem;
        $fiscalSnapshot = is_array($originItem?->fiscal_snapshot) ? $originItem->fiscal_snapshot : [];
        $taxData = is_array($originItem?->tax_data) ? $originItem->tax_data : [];

        if (($taxData['imposto'] ?? null) === null && $fiscalSnapshot !== [] && $originItem instanceof FiscalDocumentItem) {
            $taxData = array_replace_recursive(
                $taxData,
                FiscalDecisionDTO::fromArray($fiscalSnapshot)->toTaxData((float) $originItem->total_price)
            );
        }

        $fiscalSnapshot['repair_return_origin'] = [
            'service_order_id' => $serviceOrderId,
            'fiscal_document_item_id' => $originItem?->id,
            'remittance_asset_id' => $asset->id,
            'item_number' => $originItem?->item_number,
        ];

        return [
            'fiscal_document_id' => $returnDocumentId,
            'product_id' => $originItem?->product_id,
            'product_code' => $originItem?->product_code,
            'description' => $originItem?->description,
            'item_number' => $itemNumber,
            'product_origin' => $originItem?->product_origin,
            'ncm_code' => $originItem?->ncm_code,
            'cest_code' => $originItem?->cest_code,
            'barcode' => $originItem?->barcode,
            'cfop_code' => $originItem?->cfop_code ?: ($fiscalSnapshot['cfop'] ?? null),
            'quantity' => (float) $asset->received_quantity,
            'unit_of_measure' => $originItem?->unit_of_measure,
            'taxable_unit' => $originItem?->taxable_unit,
            'taxable_quantity' => $originItem?->taxable_quantity !== null ? (float) $originItem->taxable_quantity : null,
            'taxable_unit_price' => $originItem?->taxable_unit_price !== null ? (float) $originItem->taxable_unit_price : null,
            'unit_price' => (float) ($originItem?->unit_price ?? 0),
            'total_price' => (float) ($originItem?->unit_price ?? 0) * (float) $asset->received_quantity,
            'discount_amount' => $originItem?->discount_amount !== null ? (float) $originItem->discount_amount : null,
            'freight_amount' => $originItem?->freight_amount !== null ? (float) $originItem->freight_amount : null,
            'insurance_amount' => $originItem?->insurance_amount !== null ? (float) $originItem->insurance_amount : null,
            'other_expenses_amount' => $originItem?->other_expenses_amount !== null ? (float) $originItem->other_expenses_amount : null,
            'included_in_total' => (bool) ($originItem?->included_in_total ?? true),
            'tax_data' => $taxData !== [] ? $taxData : null,
            'fiscal_snapshot' => $fiscalSnapshot,
            'additional_information' => $originItem?->additional_information,
        ];
    }

    private function buildOriginReferenceText(ServiceOrder $serviceOrder, FiscalDocument $originDocument): string
    {
        $parts = array_filter([
            'OS: #'.$serviceOrder->number,
            'NF de remessa: '.($originDocument->document_number ?: $originDocument->id),
            $originDocument->document_series ? 'Série '.$originDocument->document_series : null,
            $originDocument->document_key ? 'Chave '.$originDocument->document_key : null,
        ]);

        return implode(' | ', $parts);
    }
}
