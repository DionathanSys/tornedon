<?php

namespace App\Services\Fiscal\Sefaz;

use App\Enum\Audit\AuditSource;
use App\Enum\Partner\Type as PartnerType;
use App\Enum\SefazDistributionDocument\ImportStatus;
use App\Enum\SefazDistributionDocument\ManifestationStatus;
use App\Enum\SefazDistributionDocument\Status;
use App\Models\AuditEntry;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\FiscalDocument;
use App\Models\Partner;
use App\Models\SefazDistributionDocument;
use App\Services\Audit\AuditRecorder;
use App\Services\Fiscal\Sefaz\DTO\DfeDistributionDocument;
use Illuminate\Support\Facades\Log;

class SefazDistributionDocumentService
{
    public function __construct(
        private readonly SefazDistributionDocumentParser $parser,
        private readonly SefazDfeStorageService $storageService,
        private readonly AuditRecorder $auditRecorder,
        private readonly SefazItemMappingService $itemMappingService,
    ) {}

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
        $wasRecentlyCreated = ! $record->exists;
        $previousFullXml = (bool) $record->full_xml_available;
        $previousImportStatus = $record->import_status;

        $partner = $this->resolveOrCreatePartner($company, $parsed['issuer_document'] ?? null, $parsed['issuer_name'] ?? null);
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
            'partner_id' => $partner?->id ?? $record->partner_id,
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
            'last_job_uuid' => app()->bound('queue.job') ? app('queue.job')->uuid() : null,
        ]);

        if ($isFullXml) {
            $record->status = $record->imported_at !== null ? Status::IMPORTED : Status::FULL_XML_AVAILABLE;
            $record->manifestation_status = ManifestationStatus::ACCEPTED;
            $record->full_xml_available = true;
            $record->full_xml_path = $fullPath;
            $record->items_json = $this->applyMappedProducts($company->id, $partner?->id ?? $record->partner_id, $parsed['items']);
            $record->import_ready_at ??= now();
            if ($record->import_status !== ImportStatus::IGNORED) {
                $record->import_status = $record->imported_at !== null ? ImportStatus::IMPORTED : ImportStatus::READY_TO_IMPORT;
            }
            $record->last_action = 'full_xml_available';
            $record->last_action_at = now();
            $record->last_error_code = null;
            $record->last_error_message = null;
        } else {
            $record->summary_xml_path = $summaryPath;
            $record->full_xml_available = false;
            if ($record->import_status !== ImportStatus::IGNORED) {
                $record->import_status = ImportStatus::PENDING_XML;
            }

            if ($record->manifestation_status === ManifestationStatus::ACCEPTED) {
                $record->status = Status::MANIFESTED_WAITING_FULL_XML;
            } else {
                $record->status = Status::DETECTED_SUMMARY;
                $record->manifestation_status = ManifestationStatus::PENDING;
            }

            $record->last_action = $wasRecentlyCreated ? 'detected' : 'updated_summary';
            $record->last_action_at = now();
        }

        $record->save();

        if ($wasRecentlyCreated) {
            $this->recordAuditEvent(
                $record,
                event: 'sefaz_distribution.detected',
                summary: 'DF-e detectado na distribuição da SEFAZ',
                source: AuditSource::INTEGRATION,
                metadata: [
                    'nsu' => $record->nsu,
                    'schema' => $record->schema,
                ],
            );
        }

        if (! $previousFullXml && $record->full_xml_available) {
            $this->recordAuditEvent(
                $record,
                event: 'sefaz_distribution.full_xml_available',
                summary: 'XML completo disponibilizado para importação',
                source: AuditSource::INTEGRATION,
                metadata: [
                    'nsu' => $record->nsu,
                    'schema' => $record->schema,
                ],
            );
        }

        if ($previousImportStatus !== $record->import_status && $record->import_status === ImportStatus::READY_TO_IMPORT) {
            $this->recordAuditEvent(
                $record,
                event: 'sefaz_distribution.reprocessed',
                summary: 'Documento atualizado para pronto para importar',
                source: AuditSource::INTEGRATION,
            );
        }

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
            'last_action' => 'manifestation_requested',
            'last_action_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
            'last_job_uuid' => app()->bound('queue.job') ? app('queue.job')->uuid() : $document->last_job_uuid,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.manifestation_requested',
            summary: 'Manifestação do destinatário solicitada',
            source: AuditSource::JOB,
            metadata: $payload,
        );
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
                'manifestation' => array_merge($result, [
                    'failure_type' => $accepted ? null : 'functional',
                    'retry_allowed' => false,
                ]),
            ]),
            'last_action' => $accepted ? 'manifestation_succeeded' : 'manifestation_failed',
            'last_action_at' => now(),
            'last_error_code' => $accepted ? null : (string) ($result['event_status_code'] ?? ''),
            'last_error_message' => $accepted ? null : (string) ($result['event_status_message'] ?? 'Falha funcional na manifestação'),
            'last_job_uuid' => app()->bound('queue.job') ? app('queue.job')->uuid() : $document->last_job_uuid,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: $accepted ? 'sefaz_distribution.manifestation_succeeded' : 'sefaz_distribution.manifestation_failed',
            summary: $accepted ? 'Manifestação aceita pela SEFAZ' : 'Manifestação rejeitada pela SEFAZ',
            source: AuditSource::JOB,
            metadata: $result,
        );
    }

    public function markManifestationFailure(
        SefazDistributionDocument $document,
        string $message,
        int $attemptNumber = 1,
        bool $retryAllowed = false,
    ): void {
        $document->forceFill([
            'status' => $document->full_xml_available ? Status::FULL_XML_AVAILABLE : Status::ERROR,
            'manifestation_status' => ManifestationStatus::FAILED,
            'distribution_payload' => array_merge($document->distribution_payload ?? [], [
                'manifestation' => array_merge(($document->distribution_payload['manifestation'] ?? []), [
                    'error' => $message,
                    'failure_type' => 'technical',
                    'attempt_number' => $attemptNumber,
                    'retry_allowed' => $retryAllowed,
                    'failed_at' => now()->toIso8601String(),
                ]),
            ]),
            'last_action' => 'manifestation_failed',
            'last_action_at' => now(),
            'last_error_code' => 'technical_failure',
            'last_error_message' => $message,
            'last_job_uuid' => app()->bound('queue.job') ? app('queue.job')->uuid() : $document->last_job_uuid,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.manifestation_failed',
            summary: 'Manifestação falhou por erro técnico',
            source: AuditSource::JOB,
            metadata: [
                'message' => $message,
                'attempt_number' => $attemptNumber,
                'retry_allowed' => $retryAllowed,
            ],
        );
    }

    public function prepareManualManifestationRetry(SefazDistributionDocument $document): void
    {
        $document->forceFill([
            'status' => $document->full_xml_available ? Status::FULL_XML_AVAILABLE : Status::MANIFESTATION_PENDING,
            'manifestation_status' => ManifestationStatus::PENDING,
            'distribution_payload' => array_merge($document->distribution_payload ?? [], [
                'manifestation' => array_merge(($document->distribution_payload['manifestation'] ?? []), [
                    'manual_retry_requested_at' => now()->toIso8601String(),
                    'retry_allowed' => true,
                ]),
            ]),
            'last_action' => 'manual_manifestation_retry',
            'last_action_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.reprocessed',
            summary: 'Manifestação marcada para nova tentativa manual',
            source: AuditSource::WEB,
        );
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
            'last_action' => 'refresh_failed',
            'last_action_at' => now(),
            'last_error_code' => 'refresh_failed',
            'last_error_message' => $message,
            'last_job_uuid' => app()->bound('queue.job') ? app('queue.job')->uuid() : $document->last_job_uuid,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.reprocessed',
            summary: 'Busca do XML completo falhou',
            source: AuditSource::JOB,
            metadata: [
                'error' => $message,
            ],
        );
    }

    public function markManualRefreshRequested(SefazDistributionDocument $document): void
    {
        $document->forceFill([
            'last_action' => 'manual_refresh_requested',
            'last_action_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.reprocessed',
            summary: 'Busca manual do XML completo solicitada',
            source: AuditSource::WEB,
        );
    }

    public function markImportRequested(SefazDistributionDocument $document, ?int $actorUserId = null): void
    {
        $document->forceFill([
            'import_status' => ImportStatus::IMPORTING,
            'import_attempted_at' => now(),
            'import_error' => null,
            'ignored_at' => null,
            'ignored_by' => null,
            'ignore_reason' => null,
            'last_action' => 'import_requested',
            'last_action_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.import_requested',
            summary: 'Importação do DF-e iniciada',
            actorUserId: $actorUserId,
            source: $actorUserId ? AuditSource::WEB : AuditSource::SYSTEM,
        );
    }

    public function markImportSucceeded(
        SefazDistributionDocument $document,
        FiscalDocument $fiscalDocument,
        ?int $actorUserId = null,
        bool $reusedExisting = false,
    ): void {
        $document->forceFill([
            'fiscal_document_id' => $fiscalDocument->id,
            'partner_id' => $document->partner_id ?: $fiscalDocument->customer_id,
            'status' => Status::IMPORTED,
            'import_status' => ImportStatus::IMPORTED,
            'import_error' => null,
            'imported_at' => now(),
            'imported_by' => $actorUserId,
            'last_action' => 'import_succeeded',
            'last_action_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.import_succeeded',
            summary: $reusedExisting
                ? 'DF-e vinculado a documento fiscal já existente'
                : 'DF-e importado para documento fiscal',
            actorUserId: $actorUserId,
            source: $actorUserId ? AuditSource::WEB : AuditSource::SYSTEM,
            metadata: [
                'fiscal_document_id' => $fiscalDocument->id,
                'reused_existing' => $reusedExisting,
            ],
        );
    }

    public function markImportFailure(
        SefazDistributionDocument $document,
        string $message,
        ?string $errorCode = null,
        ?int $actorUserId = null,
    ): void {
        $document->forceFill([
            'import_status' => ImportStatus::IMPORT_ERROR,
            'import_error' => $message,
            'last_action' => 'import_failed',
            'last_action_at' => now(),
            'last_error_code' => $errorCode,
            'last_error_message' => $message,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.import_failed',
            summary: 'Falha ao importar DF-e para documento fiscal',
            actorUserId: $actorUserId,
            source: $actorUserId ? AuditSource::WEB : AuditSource::SYSTEM,
            metadata: [
                'error_code' => $errorCode,
                'error' => $message,
            ],
        );
    }

    public function ignoreDocument(SefazDistributionDocument $document, string $reason, ?int $actorUserId = null): void
    {
        $document->forceFill([
            'import_status' => ImportStatus::IGNORED,
            'ignored_at' => now(),
            'ignored_by' => $actorUserId,
            'ignore_reason' => $reason,
            'last_action' => 'ignored',
            'last_action_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.ignored',
            summary: 'Documento ignorado na inbox de DF-e',
            actorUserId: $actorUserId,
            source: $actorUserId ? AuditSource::WEB : AuditSource::SYSTEM,
            metadata: [
                'reason' => $reason,
            ],
        );
    }

    public function reactivateDocument(SefazDistributionDocument $document, ?int $actorUserId = null): void
    {
        $document->forceFill([
            'import_status' => $document->full_xml_available ? ImportStatus::READY_TO_IMPORT : ImportStatus::PENDING_XML,
            'ignored_at' => null,
            'ignored_by' => null,
            'ignore_reason' => null,
            'last_action' => 'reactivated',
            'last_action_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.reprocessed',
            summary: 'Documento reativado na inbox de DF-e',
            actorUserId: $actorUserId,
            source: $actorUserId ? AuditSource::WEB : AuditSource::SYSTEM,
        );
    }

    public function updatePartnerLink(SefazDistributionDocument $document, Partner $partner, ?int $actorUserId = null): void
    {
        CompanyPartner::query()->updateOrCreate(
            [
                'company_id' => $document->company_id,
                'partner_id' => $partner->id,
            ],
            [
                'type' => [PartnerType::SUPPLIER->value],
                'invoice_threshold' => 0,
                'is_active' => true,
            ],
        );

        $document->forceFill([
            'partner_id' => $partner->id,
            'items_json' => $this->applyMappedProducts($document->company_id, $partner->id, $document->items_json),
            'last_action' => 'partner_linked',
            'last_action_at' => now(),
        ])->save();

        $this->itemMappingService->syncMappings($document->fresh(), $document->items_json ?? [], $actorUserId);

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.reprocessed',
            summary: 'Fornecedor vinculado manualmente ao DF-e',
            actorUserId: $actorUserId,
            source: AuditSource::WEB,
            metadata: [
                'partner_id' => $partner->id,
            ],
        );
    }

    public function createAndLinkSupplier(
        SefazDistributionDocument $document,
        string $name,
        string $documentNumber,
        ?int $actorUserId = null,
    ): Partner {
        $digits = preg_replace('/\D+/', '', $documentNumber) ?? '';

        if (! in_array(strlen($digits), [11, 14], true)) {
            throw new \RuntimeException('Informe um CPF ou CNPJ válido para cadastrar o fornecedor.');
        }

        $partner = $this->resolveOrCreatePartner($document->company, $digits, $name);

        if (! $partner) {
            throw new \RuntimeException('Não foi possível cadastrar o fornecedor para o DF-e.');
        }

        $this->updatePartnerLink($document->fresh(), $partner, $actorUserId);

        return $partner;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateItemMappings(SefazDistributionDocument $document, array $items, ?int $actorUserId = null): void
    {
        $this->itemMappingService->syncMappings($document, $items, $actorUserId);

        $document->forceFill([
            'items_json' => $items,
            'last_action' => 'items_linked',
            'last_action_at' => now(),
        ])->save();

        $this->recordAuditEvent(
            $document->fresh(),
            event: 'sefaz_distribution.reprocessed',
            summary: 'Itens do DF-e vinculados manualmente a produtos',
            actorUserId: $actorUserId,
            source: AuditSource::WEB,
        );
    }

    public function resolveOrCreatePartner(Company $company, ?string $issuerDocument, ?string $issuerName): ?Partner
    {
        if (! is_string($issuerDocument) || ! in_array(strlen($issuerDocument), [11, 14], true) || ! is_string($issuerName) || trim($issuerName) === '') {
            return null;
        }

        $formattedDocument = $this->formatDocumentNumber($issuerDocument);

        $partner = Partner::query()->firstOrCreate(
            ['document_number' => $formattedDocument],
            [
                'name' => trim($issuerName),
                'document_type' => strlen($issuerDocument) === 14 ? 'cnpj' : 'cpf',
                'state_tax_indicator' => '9',
                'created_by' => $company->created_by,
            ],
        );

        if ($partner->name !== trim($issuerName)) {
            $partner->forceFill([
                'name' => trim($issuerName),
                'updated_by' => $company->updated_by ?? $company->created_by,
            ])->save();
        }

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

        return $partner;
    }

    private function formatDocumentNumber(string $digits): string
    {
        return strlen($digits) === 14
            ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) ?? $digits
            : (preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) ?? $digits);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<int, array<string, mixed>>|null
     */
    private function applyMappedProducts(int $companyId, ?int $partnerId, ?array $items): ?array
    {
        if (! is_array($items)) {
            return $items;
        }

        return collect($items)
            ->map(function (array $item) use ($companyId, $partnerId): array {
                if (($item['product_id'] ?? null) !== null) {
                    return $item;
                }

                $mapping = $this->itemMappingService->findMapping(
                    $companyId,
                    $partnerId,
                    $item['product_code'] ?? null,
                );

                if (! $mapping) {
                    return $item;
                }

                $mappedProductId = $mapping->product_id;
                $partnerProduct = \App\Models\Product::query()->find($mappedProductId);
                $item['product_id'] = $mappedProductId;
                $item['product_name'] = $partnerProduct?->name;
                $item['product_unit'] = $mapping->product_unit;

                return $item;
            })
            ->all();
    }

    private function recordAuditEvent(
        SefazDistributionDocument $document,
        string $event,
        string $summary,
        ?int $actorUserId = null,
        ?AuditSource $source = null,
        array $metadata = [],
    ): ?AuditEntry {
        return $this->auditRecorder->recordModelEvent(
            $document,
            $event,
            $summary,
            null,
            $this->auditRecorder->snapshot($document),
            $actorUserId,
            $source,
            $metadata,
        );
    }
}
