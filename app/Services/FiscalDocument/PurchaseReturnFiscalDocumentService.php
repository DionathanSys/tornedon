<?php

namespace App\Services\FiscalDocument;

use App\Domain\DTO\Fiscal\FiscalContextDTO;
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
use App\Services\Fiscal\FiscalDecisionService;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseReturnFiscalDocumentService
{
    use HandlesServiceResponse;

    public function generateFromEntry(FiscalDocument $originDocument, int $userId): ?FiscalDocument
    {
        $this->resetResponse();

        try {
            return DB::transaction(function () use ($originDocument, $userId): ?FiscalDocument {
                $originDocument->loadMissing('items');

                if (! $this->validateOriginDocument($originDocument)) {
                    return null;
                }

                $fiscalDocumentService = app(FiscalDocumentService::class);
                $returnDocument = $fiscalDocumentService->create(
                    $this->buildReturnDocumentData($originDocument),
                    $userId
                );

                if ($fiscalDocumentService->hasError() || $returnDocument === null) {
                    $this->setError(
                        $fiscalDocumentService->getMessage(),
                        $fiscalDocumentService->getErrors(),
                        422,
                        $fiscalDocumentService->getErrorCode()
                    );

                    return null;
                }

                $returnDocument->loadMissing('company', 'customer.address');

                $itemPayloads = $originDocument->items
                    ->values()
                    ->map(fn (FiscalDocumentItem $item, int $index): array => $this->buildReturnItemData($item, $returnDocument, $index + 1))
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

                foreach ($originDocument->items->values() as $index => $originItem) {
                    $returnItem = $createdItemsByNumber->get($index + 1);

                    if (! $returnItem instanceof FiscalDocumentItem) {
                        $this->setError('Não foi possível alinhar os itens da nota de devolução gerada.');

                        return null;
                    }

                    FiscalDocumentItemOrigin::query()->create([
                        'origin_fiscal_document_id' => $originDocument->id,
                        'origin_fiscal_document_item_id' => $originItem->id,
                        'return_fiscal_document_id' => $returnDocument->id,
                        'return_fiscal_document_item_id' => $returnItem->id,
                        'linked_quantity' => (float) $originItem->quantity,
                        'linked_value' => (float) $originItem->total_price,
                        'origin_document_key' => $originDocument->document_key,
                        'metadata' => [
                            'origin_item_number' => $originItem->item_number,
                            'return_item_number' => $returnItem->item_number,
                            'generation_mode' => 'purchase_return_full',
                        ],
                    ]);
                }

                $this->setSuccess('Nota de devolução gerada com sucesso.');

                Log::info('PurchaseReturnFiscalDocumentService: nota de devolução gerada', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'origin_fiscal_document_id' => $originDocument->id,
                    'return_fiscal_document_id' => $returnDocument->id,
                    'items_count' => count($createdItems),
                    'user_id' => $userId,
                ]);

                return $returnDocument;
            });
        } catch (\Throwable $e) {
            $this->setError($e->getMessage());

            Log::error('PurchaseReturnFiscalDocumentService: exceção ao gerar devolução', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'origin_fiscal_document_id' => $originDocument->id,
                'user_id' => $userId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function validateOriginDocument(FiscalDocument $originDocument): bool
    {
        if (! $originDocument->isNfe()) {
            $this->setError('A devolução de compra só pode ser gerada a partir de uma NF-e de entrada.');

            return false;
        }

        if ($originDocument->operation_type !== OperationType::ENTRADA) {
            $this->setError('A devolução de compra só pode ser gerada a partir de uma nota de entrada.');

            return false;
        }

        if ($originDocument->status === \App\Enum\FiscalDocument\Status::CANCELLED || $originDocument->canceled) {
            $this->setError('Não é possível gerar devolução para uma nota de entrada cancelada.');

            return false;
        }

        if ($originDocument->items->isEmpty()) {
            $this->setError('A nota de entrada não possui itens para gerar devolução.');

            return false;
        }

        $alreadyLinked = FiscalDocumentItemOrigin::query()
            ->where('origin_fiscal_document_id', $originDocument->id)
            ->exists();

        if ($alreadyLinked) {
            $this->setError('Já existe uma nota de devolução vinculada a esta nota de entrada.');

            return false;
        }

        return true;
    }

    private function buildReturnDocumentData(FiscalDocument $originDocument): array
    {
        $freightData = is_array($originDocument->freight_data)
            ? $originDocument->freight_data
            : [];

        if (! array_key_exists('modalidade_frete', $freightData)) {
            $freightData['modalidade_frete'] = FreightModality::SEM_FRETE->value;
        }

        return [
            'customer_id' => $originDocument->customer_id,
            'company_id' => $originDocument->company_id,
            'document_type' => DocumentModel::NFE->value,
            'issued_at' => now()->toDateString(),
            'movement_at' => now()->toDateString(),
            'operation_nature' => OperationNature::DEVOLUCAO_COMPRA->value,
            'operation_type' => OperationType::SAIDA->value,
            'issue_purpose' => IssuePurpose::DEVOLUCAO->value,
            'is_final_consumer' => (bool) ($originDocument->is_final_consumer ?? false),
            'buyer_presence_indicator' => $originDocument->buyer_presence_indicator?->value
                ?? BuyerPresenceIndicator::OUTROS->value,
            'freight_data' => $freightData,
            'tax_data' => [
                'purchase_return_origin' => [
                    'fiscal_document_id' => $originDocument->id,
                    'document_number' => $originDocument->document_number,
                    'document_series' => $originDocument->document_series,
                    'document_key' => $originDocument->document_key,
                    'issued_at' => $originDocument->issued_at?->toDateString(),
                ],
            ],
            'additional_taxpayer_information' => $this->buildOriginReferenceText($originDocument),
        ];
    }

    private function buildReturnItemData(FiscalDocumentItem $originItem, FiscalDocument $returnDocument, int $itemNumber): array
    {
        $fiscalSnapshot = is_array($originItem->fiscal_snapshot) ? $originItem->fiscal_snapshot : [];
        $originTaxData = is_array($originItem->tax_data) ? $originItem->tax_data : [];
        $taxData = $originTaxData;

        $decision = $this->resolveReturnFiscalDecision($returnDocument, $originItem);

        if ($decision instanceof FiscalDecisionDTO) {
            $taxData = $decision->toTaxData((float) $originItem->total_price);
            $originIbsCbs = data_get($originTaxData, 'imposto.ibs_cbs');

            if ($this->isCompleteIbsCbs($originIbsCbs)) {
                data_set($taxData, 'imposto.ibs_cbs', $originIbsCbs);
            }
        } elseif (($taxData['imposto'] ?? null) === null && $fiscalSnapshot !== []) {
            $taxData = array_replace_recursive(
                $taxData,
                FiscalDecisionDTO::fromArray($fiscalSnapshot)->toTaxData((float) $originItem->total_price)
            );
        }

        if (is_array(data_get($taxData, 'imposto.ibs_cbs')) && ! $this->isCompleteIbsCbs(data_get($taxData, 'imposto.ibs_cbs'))) {
            data_forget($taxData, 'imposto.ibs_cbs');
        }

        $fiscalSnapshot['purchase_return_origin'] = [
            'fiscal_document_item_id' => $originItem->id,
            'item_number' => $originItem->item_number,
            'tax_resolution_source' => $decision instanceof FiscalDecisionDTO ? $decision->source : 'origin_tax_data',
        ];

        return [
            'fiscal_document_id' => $returnDocument->id,
            'product_id' => $originItem->product_id,
            'product_code' => $originItem->product_code,
            'description' => $originItem->description,
            'item_number' => $itemNumber,
            'product_origin' => $originItem->product_origin,
            'ncm_code' => $originItem->ncm_code,
            'cest_code' => $originItem->cest_code,
            'barcode' => $originItem->barcode,
            'cfop_code' => $decision?->cfop ?: $originItem->cfop_code ?: ($fiscalSnapshot['cfop'] ?? null),
            'quantity' => (float) $originItem->quantity,
            'unit_of_measure' => $originItem->unit_of_measure,
            'taxable_unit' => $originItem->taxable_unit,
            'taxable_quantity' => $originItem->taxable_quantity !== null ? (float) $originItem->taxable_quantity : null,
            'taxable_unit_price' => $originItem->taxable_unit_price !== null ? (float) $originItem->taxable_unit_price : null,
            'unit_price' => (float) $originItem->unit_price,
            'total_price' => (float) $originItem->total_price,
            'discount_amount' => $originItem->discount_amount !== null ? (float) $originItem->discount_amount : null,
            'freight_amount' => $originItem->freight_amount !== null ? (float) $originItem->freight_amount : null,
            'insurance_amount' => $originItem->insurance_amount !== null ? (float) $originItem->insurance_amount : null,
            'other_expenses_amount' => $originItem->other_expenses_amount !== null ? (float) $originItem->other_expenses_amount : null,
            'included_in_total' => (bool) $originItem->included_in_total,
            'tax_data' => $taxData !== [] ? $taxData : null,
            'fiscal_snapshot' => $fiscalSnapshot,
            'additional_information' => $originItem->additional_information,
        ];
    }

    private function resolveReturnFiscalDecision(FiscalDocument $returnDocument, FiscalDocumentItem $originItem): ?FiscalDecisionDTO
    {
        try {
            $contextItem = new FiscalDocumentItem([
                'product_id' => $originItem->product_id,
                'ncm_code' => $originItem->ncm_code,
                'cest_code' => $originItem->cest_code,
                'product_origin' => $originItem->product_origin,
            ]);

            $decision = app(FiscalDecisionService::class)->resolve(
                FiscalContextDTO::fromFiscalDocumentItem($returnDocument, $contextItem)
            );

            return in_array($decision->source, ['fiscal_rule', 'product_tax'], true) ? $decision : null;
        } catch (\Throwable $e) {
            Log::warning('PurchaseReturnFiscalDocumentService: falha ao resolver regra fiscal da devolucao', [
                'metodo' => __METHOD__.'@'.__LINE__,
                'return_fiscal_document_id' => $returnDocument->id,
                'origin_fiscal_document_item_id' => $originItem->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function isCompleteIbsCbs(mixed $ibsCbs): bool
    {
        if (! is_array($ibsCbs) || $ibsCbs === []) {
            return false;
        }

        foreach ([
            'situacao_tributaria',
            'classificacao_tributaria',
            'grupo_ibs_cbs.valor_base_calculo',
            'grupo_ibs_cbs.ibs_estadual.aliquota',
            'grupo_ibs_cbs.ibs_estadual.valor',
            'grupo_ibs_cbs.ibs_municipal.aliquota',
            'grupo_ibs_cbs.ibs_municipal.valor',
            'grupo_ibs_cbs.cbs.aliquota',
            'grupo_ibs_cbs.cbs.valor',
        ] as $field) {
            if (blank(data_get($ibsCbs, $field))) {
                return false;
            }
        }

        return true;
    }

    private function buildOriginReferenceText(FiscalDocument $originDocument): string
    {
        $parts = array_filter([
            'NF de origem: '.($originDocument->document_number ?: $originDocument->id),
            $originDocument->document_series ? 'Série '.$originDocument->document_series : null,
            $originDocument->document_key ? 'Chave '.$originDocument->document_key : null,
        ]);

        return implode(' | ', $parts);
    }
}
