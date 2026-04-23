<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\Audit\AuditSource;
use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\NfeStatus;
use App\Enum\FiscalDocument\OperationType;
use App\Enum\FiscalDocument\Status as FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentItem;
use App\Models\Product;
use App\Models\SefazDistributionDocument;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

class SefazDistributionFiscalDocumentImportService
{
    public function __construct(
        private readonly SefazDfeStorageService $storageService,
        private readonly SefazDistributionFiscalDocumentXmlParser $parser,
        private readonly SefazDistributionDocumentService $distributionDocumentService,
        private readonly AuditRecorder $auditRecorder,
        private readonly SefazItemMappingService $itemMappingService,
    ) {
    }

    public function import(SefazDistributionDocument $distributionDocument, ?int $actorUserId = null): FiscalDocument
    {
        $distributionDocument->loadMissing(['company', 'partner', 'fiscalDocument']);

        if (! $distributionDocument->company) {
            throw new \RuntimeException('Não foi possível resolver a empresa do DF-e para importar o documento.');
        }

        if (! $distributionDocument->full_xml_available) {
            throw new \RuntimeException('O documento ainda não possui XML completo disponível para importação.');
        }

        $xml = $this->storageService->read($distributionDocument->full_xml_path);

        if (! is_string($xml) || trim($xml) === '') {
            throw new \RuntimeException('O XML completo do DF-e não foi encontrado no storage.');
        }

        return DB::transaction(function () use ($distributionDocument, $actorUserId, $xml): FiscalDocument {
            $locked = SefazDistributionDocument::query()
                ->whereKey($distributionDocument->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->distributionDocumentService->markImportRequested($locked, $actorUserId);

            try {
                if ($locked->fiscal_document_id !== null) {
                    $fiscalDocument = FiscalDocument::query()->find($locked->fiscal_document_id);

                    if ($fiscalDocument instanceof FiscalDocument) {
                        $this->distributionDocumentService->markImportSucceeded($locked, $fiscalDocument, $actorUserId, reusedExisting: true);

                        return $fiscalDocument;
                    }
                }

                $existing = FiscalDocument::query()
                    ->where('company_id', $locked->company_id)
                    ->where('document_key', $locked->document_key)
                    ->first();

                if ($existing instanceof FiscalDocument) {
                    $this->distributionDocumentService->markImportSucceeded($locked, $existing, $actorUserId, reusedExisting: true);

                    return $existing;
                }

                $parsed = $this->parser->parse($xml);
                $partner = $locked->partner
                    ?? $this->distributionDocumentService->resolveOrCreatePartner(
                        $locked->company,
                        $parsed['issuer']['document'] ?? $locked->issuer_document,
                        $parsed['issuer']['name'] ?? $locked->issuer_name,
                    );

                if (! $partner) {
                    throw new \RuntimeException('Não foi possível resolver o fornecedor do documento antes da importação.');
                }

                $items = $this->buildItemsPayload($locked, $parsed['items']);

                $fiscalDocument = FiscalDocument::query()->create([
                    'customer_id' => $partner->id,
                    'company_id' => $locked->company_id,
                    'status' => FiscalDocumentStatus::PENDING,
                    'issued_at' => $this->toDate($parsed['header']['issued_at'] ?? null) ?? now()->toDateString(),
                    'movement_at' => $this->toDate($parsed['header']['movement_at'] ?? null)
                        ?? $this->toDate($parsed['header']['issued_at'] ?? null)
                        ?? now()->toDateString(),
                    'document_type' => DocumentModel::NFE,
                    'document_key' => $parsed['header']['document_key'] ?? $locked->document_key,
                    'document_number' => $parsed['header']['document_number'] ?? $locked->document_number,
                    'document_series' => $parsed['header']['document_series'] ?? $locked->document_series,
                    'operation_type' => OperationType::ENTRADA,
                    'operation_nature' => $parsed['header']['operation_nature'] ?? null,
                    'issue_purpose' => $parsed['header']['issue_purpose'] ?? null,
                    'is_final_consumer' => (bool) ($parsed['header']['is_final_consumer'] ?? false),
                    'buyer_presence_indicator' => $parsed['header']['buyer_presence_indicator'] ?? null,
                    'tax_observations' => $parsed['additional_info']['tax_observations'] ?? null,
                    'taxpayer_observations' => $parsed['additional_info']['taxpayer_observations'] ?? null,
                    'additional_purchase_information' => $parsed['header']['raw_operation_nature'] ?? null,
                    'freight_data' => $parsed['transport'],
                    'payment_data' => $parsed['payment'],
                    'tax_data' => [
                        'totals' => $parsed['totals'],
                        'protocol' => $parsed['protocol'],
                    ],
                    'pending' => true,
                    'confirmed' => false,
                    'canceled' => false,
                    'created_by' => $actorUserId,
                    'updated_by' => $actorUserId,
                    'nfe_status' => null,
                    'nfe_payload' => [
                        'import_origin' => 'sefaz_distribution',
                        'distribution_document_id' => $locked->id,
                        'summary_xml_path' => $locked->summary_xml_path,
                        'full_xml_path' => $locked->full_xml_path,
                        'raw_response_path' => $locked->raw_response_path,
                        'issuer' => $parsed['issuer'],
                        'recipient' => $parsed['recipient'],
                        'protocol' => $parsed['protocol'],
                        'xml' => $xml,
                        'items_count' => count($items),
                    ],
                    'logs' => [
                        'imported_from_dfe' => true,
                        'distribution_document_id' => $locked->id,
                    ],
                ]);

                foreach ($items as $item) {
                    FiscalDocumentItem::query()->create([
                        'fiscal_document_id' => $fiscalDocument->id,
                        'product_id' => $item['product_id'],
                        'product_code' => $item['product_code'],
                        'description' => $item['description'],
                        'item_number' => $item['item_number'],
                        'product_origin' => $item['product_origin'],
                        'ncm_code' => $item['ncm_code'],
                        'cest_code' => $item['cest_code'],
                        'barcode' => $item['barcode'],
                        'cfop_code' => $item['cfop_code'],
                        'quantity' => $item['quantity'],
                        'unit_of_measure' => $item['unit_of_measure'],
                        'taxable_unit' => $item['taxable_unit'],
                        'taxable_quantity' => $item['taxable_quantity'],
                        'taxable_unit_price' => $item['taxable_unit_price'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price'],
                        'discount_amount' => $item['discount_amount'],
                        'freight_amount' => $item['freight_amount'],
                        'insurance_amount' => $item['insurance_amount'],
                        'other_expenses_amount' => $item['other_expenses_amount'],
                        'tax_data' => $item['tax_data'],
                        'fiscal_snapshot' => $item['fiscal_snapshot'],
                        'additional_information' => $item['additional_information'],
                        'created_by' => $actorUserId,
                        'updated_by' => $actorUserId,
                    ]);
                }

                $this->itemMappingService->syncMappings($locked->fresh(['partner']), $items, $actorUserId);

                $this->auditRecorder->recordModelEvent(
                    $fiscalDocument,
                    'fiscal_document.created',
                    'Documento fiscal importado a partir do DF-e detectado',
                    null,
                    $this->auditRecorder->snapshot($fiscalDocument),
                    $actorUserId,
                    $actorUserId ? AuditSource::WEB : AuditSource::SYSTEM,
                    [
                        'source' => 'sefaz_distribution',
                        'distribution_document_id' => $locked->id,
                    ],
                );

                $this->distributionDocumentService->markImportSucceeded($locked, $fiscalDocument, $actorUserId);

                return $fiscalDocument;
            } catch (\Throwable $exception) {
                $this->distributionDocumentService->markImportFailure($locked, $exception->getMessage(), actorUserId: $actorUserId);

                throw $exception;
            }
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsedItems
     * @return array<int, array<string, mixed>>
     */
    private function buildItemsPayload(SefazDistributionDocument $distributionDocument, array $parsedItems): array
    {
        $mappedItems = collect($distributionDocument->items_json ?? [])
            ->mapWithKeys(function (array $item): array {
                $line = $item['line'] ?? null;

                return $line !== null ? [(string) $line => $item] : [];
            });

        return collect($parsedItems)
            ->map(function (array $item) use ($distributionDocument, $mappedItems): array {
                $mapped = $mappedItems->get((string) ($item['line'] ?? '')) ?? [];
                $productId = $mapped['product_id'] ?? null;

                if ($productId === null) {
                    $productId = $this->itemMappingService->findMappedProductId(
                        $distributionDocument->company_id,
                        $distributionDocument->partner_id,
                        $item['product_code'] ?? null,
                    );
                }

                if ($productId === null) {
                    $productId = $this->resolveProductId($distributionDocument->company_id, $item['product_code'] ?? null, $item['barcode'] ?? null);
                }

                return [
                    'product_id' => $productId,
                    'product_code' => $item['product_code'] ?? null,
                    'description' => $item['description'] ?? null,
                    'item_number' => $item['line'] ?? null,
                    'product_origin' => $item['product_origin'] ?? null,
                    'ncm_code' => $item['ncm_code'] ?? null,
                    'cest_code' => $item['cest_code'] ?? null,
                    'barcode' => $item['barcode'] ?? null,
                    'cfop_code' => $item['cfop_code'] ?? null,
                    'quantity' => $item['quantity'] ?? null,
                    'unit_of_measure' => $item['unit_of_measure'] ?? null,
                    'taxable_unit' => $item['taxable_unit'] ?? null,
                    'taxable_quantity' => $item['taxable_quantity'] ?? null,
                    'taxable_unit_price' => $item['taxable_unit_price'] ?? null,
                    'unit_price' => $item['unit_price'] ?? null,
                    'total_price' => $item['total_price'] ?? null,
                    'discount_amount' => $item['discount_amount'] ?? null,
                    'freight_amount' => $item['freight_amount'] ?? null,
                    'insurance_amount' => $item['insurance_amount'] ?? null,
                    'other_expenses_amount' => $item['other_expenses_amount'] ?? null,
                    'tax_data' => $item['tax_data'] ?? [],
                    'fiscal_snapshot' => [
                        'product' => $item['product_payload'] ?? [],
                        'det' => $item['det_payload'] ?? [],
                    ],
                    'additional_information' => $item['additional_information'] ?? null,
                ];
            })
            ->all();
    }

    private function resolveProductId(int $companyId, ?string $productCode, ?string $barcode): ?int
    {
        if (is_string($productCode) && trim($productCode) !== '') {
            $product = Product::query()
                ->where('company_id', $companyId)
                ->where('product_code', trim($productCode))
                ->first();

            if ($product) {
                return $product->id;
            }
        }

        if (is_string($barcode) && trim($barcode) !== '') {
            $product = Product::query()
                ->where('company_id', $companyId)
                ->where('barcode', trim($barcode))
                ->first();

            if ($product) {
                return $product->id;
            }
        }

        return null;
    }

    private function toDate(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
