<?php

namespace App\Services\TemporaryMigration;

use App\Enum\Partner\Type as PartnerType;
use App\Models\Company;
use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\User;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TemporaryPartnerImportService
{
    use HandlesServiceResponse;

    public function __construct(
        private PartnerMigrationApiClient $client,
    ) {}

    public function import(int $companyId, int $userId, array $filters = []): ?array
    {
        $this->resetResponse();

        $company = Company::query()->find($companyId);
        $user = User::query()->find($userId);

        if (! $company) {
            $this->setError('Empresa nao encontrada.', ['company_id' => ['Empresa informada nao existe.']], 404);

            return null;
        }

        if (! $user) {
            $this->setError('Usuario nao encontrado.', ['user_id' => ['Usuario informado nao existe.']], 404);

            return null;
        }

        $summary = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'pages' => 0,
            'records_received' => 0,
            'partners_created' => 0,
            'partners_updated' => 0,
            'company_links_created' => 0,
            'company_links_updated' => 0,
            'soft_deleted' => 0,
            'restored' => 0,
            'errors' => [],
            'last_after_id' => (int) ($filters['after_id'] ?? 0),
        ];

        $afterId = (int) ($filters['after_id'] ?? 0);
        $pageLimit = max(1, min(1000, (int) ($filters['limit'] ?? 500)));
        $maxPages = max(0, (int) ($filters['max_pages'] ?? 0));

        try {
            do {
                if ($maxPages > 0 && $summary['pages'] >= $maxPages) {
                    break;
                }

                $payload = $this->client->fetchPage([
                    'limit' => $pageLimit,
                    'after_id' => $afterId,
                    'updated_from' => $filters['updated_from'] ?? null,
                    'include_deleted' => (bool) ($filters['include_deleted'] ?? false),
                ]);

                $summary['pages']++;

                foreach ($payload['data'] as $record) {
                    $summary['records_received']++;

                    try {
                        $result = $this->importRecord($companyId, $userId, $record);
                        $summary['partners_created'] += (int) $result['partner_created'];
                        $summary['partners_updated'] += (int) $result['partner_updated'];
                        $summary['company_links_created'] += (int) $result['company_link_created'];
                        $summary['company_links_updated'] += (int) $result['company_link_updated'];
                        $summary['soft_deleted'] += (int) $result['soft_deleted'];
                        $summary['restored'] += (int) $result['restored'];
                        $summary['last_after_id'] = max($summary['last_after_id'], (int) $result['legacy_id']);
                    } catch (\Throwable $e) {
                        $legacyId = (int) ($record['legacy_id'] ?? 0);
                        $summary['errors'][] = [
                            'legacy_id' => $legacyId,
                            'message' => $e->getMessage(),
                        ];

                        Log::error(__METHOD__ . '@' . __LINE__, [
                            'message' => 'Falha ao importar parceiro da API de migracao',
                            'company_id' => $companyId,
                            'user_id' => $userId,
                            'legacy_id' => $legacyId,
                            'payload' => $record,
                            'exception' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                    }
                }

                $meta = is_array($payload['meta']) ? $payload['meta'] : [];
                $hasMore = (bool) ($meta['has_more'] ?? false);
                $nextAfterId = (int) ($meta['next_after_id'] ?? 0);

                if ($hasMore && $nextAfterId <= $afterId) {
                    throw new \RuntimeException('Paginacao invalida da API de migracao: next_after_id nao avancou.');
                }

                $afterId = $nextAfterId;
            } while ($hasMore);

            $message = 'Importacao temporaria de parceiros concluida.';

            if ($summary['errors'] !== []) {
                $message = 'Importacao temporaria de parceiros concluida com falhas.';
            }

            $this->setSuccess($message, $summary, $summary['errors'] === [] ? 200 : 207);

            return $summary;
        } catch (\Throwable $e) {
            $this->setError('Falha ao importar parceiros da API de migracao.', [$e->getMessage()], 500);

            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Falha ao importar parceiros da API de migracao',
                'company_id' => $companyId,
                'user_id' => $userId,
                'filters' => $filters,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function importRecord(int $companyId, int $userId, array $record): array
    {
        $normalized = $this->normalizeRecord($record);

        return DB::transaction(function () use ($companyId, $userId, $record, $normalized): array {
            $link = TemporaryPartnerMigrationLink::query()
                ->where('company_id', $companyId)
                ->where('legacy_id', $normalized['legacy_id'])
                ->first();

            $partner = $this->resolvePartner($link, $normalized['document_number']);
            $companyLinkCreated = false;
            $companyLinkUpdated = false;
            $partnerCreated = false;
            $partnerUpdated = false;
            $softDeleted = false;
            $restored = false;

            if (! $partner) {
                $partner = new Partner();
                $partner->created_by = $userId;
                $partnerCreated = true;
            } else {
                $partnerUpdated = true;
            }

            $partner->forceFill([
                'name' => $normalized['name'],
                'document_type' => $normalized['document_type'],
                'document_number' => $normalized['document_number'],
                'state_tax_id' => $normalized['state_tax_id'],
                'state_tax_indicator' => $normalized['state_tax_indicator'],
                'updated_by' => $userId,
            ])->saveQuietly();

            if ($normalized['deleted_at'] !== null) {
                if (! $partner->trashed()) {
                    Partner::withoutEvents(fn () => $partner->delete());
                    $softDeleted = true;
                }

                $partner->forceFill([
                    'deleted_at' => $normalized['deleted_at'],
                ])->saveQuietly();
            } elseif ($partner->trashed()) {
                Partner::withoutEvents(fn () => $partner->restore());
                $restored = true;
            }

            $companyPartner = CompanyPartner::query()
                ->where('company_id', $companyId)
                ->where('partner_id', $partner->id)
                ->first();

            $companyPartnerPayload = $this->buildCompanyPartnerPayload($normalized, $companyPartner);

            if (! $companyPartner) {
                $companyPartner = new CompanyPartner();
                $companyPartner->company_id = $companyId;
                $companyPartner->partner_id = $partner->id;
                $companyLinkCreated = true;
            } else {
                $companyLinkUpdated = true;
            }

            $companyPartner->forceFill($companyPartnerPayload)->saveQuietly();

            TemporaryPartnerMigrationLink::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'legacy_id' => $normalized['legacy_id'],
                ],
                [
                    'partner_id' => $partner->id,
                    'company_partner_id' => $companyPartner->id,
                    'legacy_document_number' => $normalized['raw_document_number'],
                    'legacy_updated_at' => $normalized['updated_at'],
                    'legacy_deleted_at' => $normalized['deleted_at'],
                    'payload' => $record,
                    'last_imported_at' => now(),
                ]
            );

            return [
                'legacy_id' => $normalized['legacy_id'],
                'partner_created' => $partnerCreated,
                'partner_updated' => $partnerUpdated,
                'company_link_created' => $companyLinkCreated,
                'company_link_updated' => $companyLinkUpdated,
                'soft_deleted' => $softDeleted,
                'restored' => $restored,
            ];
        });
    }

    private function resolvePartner(?TemporaryPartnerMigrationLink $link, string $documentNumber): ?Partner
    {
        if ($link?->partner_id) {
            $partner = Partner::withTrashed()->find($link->partner_id);

            if ($partner) {
                return $partner;
            }
        }

        return Partner::withTrashed()
            ->where('document_number', $documentNumber)
            ->first();
    }

    private function normalizeRecord(array $record): array
    {
        $legacyId = (int) ($record['legacy_id'] ?? 0);

        if ($legacyId <= 0) {
            throw new \InvalidArgumentException('Registro sem legacy_id valido.');
        }

        $rawDocumentNumber = trim((string) ($record['nro_documento'] ?? ''));
        $documentType = $this->normalizeDocumentType($record['tipo_documento'] ?? null, $rawDocumentNumber);
        $documentNumber = $this->normalizeDocumentNumber($documentType, $rawDocumentNumber);
        $stateTax = $this->normalizeStateTaxData($record['inscricao_estadual'] ?? null);
        $deletedAt = $this->parseDateTime($record['deleted_at'] ?? null);

        return [
            'legacy_id' => $legacyId,
            'name' => $this->normalizeName($record['nome'] ?? null, $legacyId),
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'raw_document_number' => $rawDocumentNumber,
            'legacy_types' => $this->mapPartnerTypes($record['tipo_vinculo'] ?? null),
            'is_active' => (bool) ($record['ativo'] ?? true),
            'state_tax_id' => $stateTax['state_tax_id'],
            'state_tax_indicator' => $stateTax['state_tax_indicator'],
            'updated_at' => $this->parseDateTime($record['updated_at'] ?? null),
            'deleted_at' => $deletedAt,
        ];
    }

    private function buildCompanyPartnerPayload(array $normalized, ?CompanyPartner $existing): array
    {
        $existingTypes = collect($existing?->type ?? [])
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();

        return [
            'type' => array_values(array_unique([
                ...$existingTypes,
                ...$normalized['legacy_types'],
            ])),
            'invoice_threshold' => $existing?->invoice_threshold ?? 0,
            'customer_discount_percentage' => $existing?->customer_discount_percentage ?? 0,
            'payment_method' => $existing?->payment_method?->value ?? $existing?->getRawOriginal('payment_method'),
            'payment_condition' => $existing?->payment_condition?->value ?? $existing?->getRawOriginal('payment_condition'),
            'is_active' => $normalized['is_active'] && $normalized['deleted_at'] === null,
            'notify_service_order_closed' => $existing?->notify_service_order_closed ?? false,
            'notify_requisition_closed' => $existing?->notify_requisition_closed ?? false,
            'notify_production_order_closed' => $existing?->notify_production_order_closed ?? false,
            'notify_invoice_confirmed' => $existing?->notify_invoice_confirmed ?? false,
            'notify_fiscal_document_confirmed' => $existing?->notify_fiscal_document_confirmed ?? false,
            'email_to_override' => $existing?->email_to_override,
            'email_cc_override' => $existing?->email_cc_override,
            'email_bcc_override' => $existing?->email_bcc_override,
        ];
    }

    private function normalizeName(mixed $value, int $legacyId): string
    {
        $name = trim((string) $value);

        if ($name === '') {
            $name = 'Parceiro legado #' . $legacyId;
        }

        return mb_substr($name, 0, 60);
    }

    private function normalizeDocumentType(mixed $value, string $documentNumber): string
    {
        $type = Str::lower(trim((string) $value));

        if (in_array($type, ['cpf', 'cnpj'], true)) {
            return $type;
        }

        $digits = preg_replace('/\D+/', '', $documentNumber);

        return match (strlen($digits)) {
            11 => 'cpf',
            14 => 'cnpj',
            default => throw new \InvalidArgumentException('Tipo ou numero de documento invalido.'),
        };
    }

    private function normalizeDocumentNumber(string $documentType, string $documentNumber): string
    {
        $digits = preg_replace('/\D+/', '', $documentNumber);

        return match ($documentType) {
            'cpf' => $this->formatCpf($digits),
            'cnpj' => $this->formatCnpj($digits),
            default => throw new \InvalidArgumentException('Tipo de documento nao suportado.'),
        };
    }

    private function formatCpf(string $digits): string
    {
        if (strlen($digits) !== 11) {
            throw new \InvalidArgumentException('CPF invalido para importacao.');
        }

        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $digits) ?? $digits;
    }

    private function formatCnpj(string $digits): string
    {
        if (strlen($digits) !== 14) {
            throw new \InvalidArgumentException('CNPJ invalido para importacao.');
        }

        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $digits) ?? $digits;
    }

    private function normalizeStateTaxData(mixed $value): array
    {
        $stateTaxId = trim((string) $value);

        if ($stateTaxId === '') {
            return [
                'state_tax_id' => null,
                'state_tax_indicator' => '9',
            ];
        }

        if (Str::contains(Str::upper($stateTaxId), 'ISENTO')) {
            return [
                'state_tax_id' => null,
                'state_tax_indicator' => '2',
            ];
        }

        return [
            'state_tax_id' => $stateTaxId,
            'state_tax_indicator' => '1',
        ];
    }

    private function mapPartnerTypes(mixed $value): array
    {
        $parts = preg_split('/[,;|\/]+/', Str::upper(trim((string) $value))) ?: [];
        $mapped = [];

        foreach ($parts as $part) {
            $part = trim($part);

            $mapped[] = match ($part) {
                'CLIENTE' => PartnerType::CUSTOMER->value,
                'COLABORADOR' => PartnerType::EMPLOYEE->value,
                'FORNECEDOR' => PartnerType::SUPPLIER->value,
                'VENDEDOR' => PartnerType::SALESPERSON->value,
                'FUNCIONARIO' => PartnerType::EMPLOYEE->value,
                'TRANSPORTADORA' => PartnerType::CARRIER->value,
                'TECNICO' => PartnerType::TECHNICIAN->value,
                default => null,
            };
        }

        $mapped = array_values(array_unique(array_filter($mapped)));

        return $mapped === [] ? [PartnerType::CUSTOMER->value] : $mapped;
    }

    private function parseDateTime(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
