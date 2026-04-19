<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\Partner\Type as PartnerType;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\SefazDistributionDocument;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use Illuminate\Support\Facades\Log;

class SefazDistributionDocumentService
{
    public function __construct(
        private readonly SefazDistributionDocumentParser $parser,
        private readonly SefazDfeStorageService $storageService,
    ) {
    }

    public function persistFromDistribution(
        Company $company,
        DfeDistributionDocument $document,
        string $rawResponsePath,
    ): ?SefazDistributionDocument {
        $parsed = $this->parser->parse($document);
        $documentKey = $parsed['document_key'] ?? $document->accessKey;

        if (! is_string($documentKey) || strlen($documentKey) !== 44) {
            Log::warning('SefazDistributionDocumentService: documento ignorado por falta de chave', [
                'company_id' => $company->id,
                'nsu' => $document->nsu,
                'schema' => $document->schema,
            ]);

            return null;
        }

        $record = SefazDistributionDocument::query()->firstOrNew([
            'company_id' => $company->id,
            'document_key' => $documentKey,
        ]);

        $partnerId = $this->resolvePartnerId($company, $parsed['issuer_document'] ?? null, $parsed['issuer_name'] ?? null);
        $isFullXml = (bool) ($parsed['is_full_xml'] ?? false);

        $summaryPath = $record->summary_xml_path;
        $fullPath = $record->full_xml_path;

        if ($isFullXml) {
            $fullPath = $this->storageService->storeFullXml($company, $documentKey, $document->nsu, $document->xml);
        } else {
            $summaryPath = $this->storageService->storeSummaryXml($company, $documentKey, $document->nsu, $document->xml);
        }

        $payload = array_merge($record->distribution_payload ?? [], [
            'last_schema' => $document->schema,
            'last_nsu' => $document->nsu,
            'document_summary' => $document->toSummary(),
            'parsed' => $parsed['payload'] ?? [],
        ]);

        $record->fill([
            'partner_id' => $partnerId ?? $record->partner_id,
            'nsu' => $document->nsu !== '' ? $document->nsu : $record->nsu,
            'schema' => $document->schema,
            'document_type' => 'nfe',
            'issuer_document' => $parsed['issuer_document'] ?? $record->issuer_document,
            'issuer_name' => $parsed['issuer_name'] ?? $record->issuer_name,
            'document_number' => $parsed['document_number'] ?? $record->document_number,
            'document_series' => $parsed['document_series'] ?? $record->document_series,
            'issued_at' => $parsed['issued_at'] ?? $record->issued_at,
            'total_amount' => $parsed['total_amount'] ?? $record->total_amount,
            'raw_response_path' => $rawResponsePath !== '' ? $rawResponsePath : $record->raw_response_path,
            'distribution_payload' => $payload,
            'last_seen_at' => now(),
        ]);

        if ($isFullXml) {
            $record->status = $record->imported_at !== null ? Status::IMPORTED : Status::FULL_XML_AVAILABLE;
            $record->manifestation_status = ManifestationStatus::ACCEPTED;
            $record->full_xml_available = true;
            $record->full_xml_path = $fullPath;
            $record->items_json = $parsed['items'];
            $record->import_ready_at ??= now();
        } else {
            $record->summary_xml_path = $summaryPath;
            $record->full_xml_available = false;

            if ($record->manifestation_status === ManifestationStatus::ACCEPTED) {
                $record->status = Status::MANIFESTED_WAITING_FULL_XML;
            } else {
                $record->status = Status::DETECTED_SUMMARY;
                $record->manifestation_status = ManifestationStatus::PENDING;
            }
        }

        $record->save();

        return $record;
    }

    public function markManifestationSent(SefazDistributionDocument $document, array $payload = []): void
    {
        $distributionPayload = array_merge($document->distribution_payload ?? [], [
            'manifestation' => array_merge(($document->distribution_payload['manifestation'] ?? []), $payload),
        ]);

        $document->forceFill([
            'status' => $document->full_xml_available ? Status::FULL_XML_AVAILABLE : Status::MANIFESTATION_PENDING,
            'manifestation_status' => ManifestationStatus::SENT,
            'distribution_payload' => $distributionPayload,
        ])->save();
    }

    public function markManifestationResult(SefazDistributionDocument $document, array $result): void
    {
        $accepted = in_array((string) ($result['event_status_code'] ?? ''), ['135', '136', '573'], true);

        $document->forceFill([
            'status' => $accepted
                ? ($document->full_xml_available ? Status::FULL_XML_AVAILABLE : Status::MANIFESTED_WAITING_FULL_XML)
                : Status::ERROR,
            'manifestation_status' => $accepted ? ManifestationStatus::ACCEPTED : ManifestationStatus::REJECTED,
            'distribution_payload' => array_merge($document->distribution_payload ?? [], [
                'manifestation' => $result,
            ]),
        ])->save();
    }

    public function markManifestationFailure(SefazDistributionDocument $document, string $message): void
    {
        $document->forceFill([
            'status' => $document->full_xml_available ? Status::FULL_XML_AVAILABLE : Status::ERROR,
            'manifestation_status' => ManifestationStatus::FAILED,
            'distribution_payload' => array_merge($document->distribution_payload ?? [], [
                'manifestation' => array_merge(($document->distribution_payload['manifestation'] ?? []), [
                    'error' => $message,
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]),
        ])->save();
    }

    public function markRefreshFailure(SefazDistributionDocument $document, string $message): void
    {
        if ($document->full_xml_available) {
            return;
        }

        $document->forceFill([
            'status' => Status::ERROR,
            'distribution_payload' => array_merge($document->distribution_payload ?? [], [
                'refresh' => [
                    'error' => $message,
                    'failed_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();
    }

    private function resolvePartnerId(Company $company, ?string $issuerDocument, ?string $issuerName): ?int
    {
        if (! is_string($issuerDocument) || ! in_array(strlen($issuerDocument), [11, 14], true) || ! is_string($issuerName) || trim($issuerName) === '') {
            return null;
        }

        $partner = Partner::query()->firstOrCreate(
            ['document_number' => $this->formatDocumentNumber($issuerDocument)],
            [
                'name' => trim($issuerName),
                'document_type' => strlen($issuerDocument) === 14 ? 'cnpj' : 'cpf',
                'state_tax_indicator' => '9',
                'created_by' => $company->created_by,
            ],
        );

        CompanyPartner::query()->updateOrCreate(
            [
                'company_id' => $company->id,
                'partner_id' => $partner->id,
            ],
            [
                'type' => [PartnerType::SUPPLIER->value],
                'invoice_threshold' => 0,
                'is_active' => true,
            ],
        );

        return $partner->id;
    }

    private function formatDocumentNumber(string $digits): string
    {
        return strlen($digits) === 14
            ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) ?? $digits
            : (preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) ?? $digits);
    }
}
