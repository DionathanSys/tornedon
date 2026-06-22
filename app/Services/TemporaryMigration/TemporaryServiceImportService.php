<?php

namespace App\Services\TemporaryMigration;

use App\Models\Company;
use App\Models\Service;
use App\Models\TemporaryServiceMigrationLink;
use App\Models\User;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TemporaryServiceImportService
{
    use HandlesServiceResponse;

    public function __construct(private ServiceMigrationApiClient $client) {}

    public function import(int $companyId, int $userId, array $filters = []): ?array
    {
        $this->resetResponse();

        if (! Company::query()->find($companyId)) {
            $this->setError('Empresa nao encontrada.', ['company_id' => ['Empresa informada nao existe.']], 404);
            return null;
        }

        if (! User::query()->find($userId)) {
            $this->setError('Usuario nao encontrado.', ['user_id' => ['Usuario informado nao existe.']], 404);
            return null;
        }

        $summary = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'pages' => 0,
            'records_received' => 0,
            'services_created' => 0,
            'services_updated' => 0,
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
                    'ativo' => $filters['ativo'] ?? null,
                ]);

                $summary['pages']++;

                foreach ($payload['data'] as $record) {
                    $summary['records_received']++;

                    try {
                        $result = $this->importRecord($companyId, $userId, $record);
                        $summary['services_created'] += (int) $result['service_created'];
                        $summary['services_updated'] += (int) $result['service_updated'];
                        $summary['soft_deleted'] += (int) $result['soft_deleted'];
                        $summary['restored'] += (int) $result['restored'];
                        $summary['last_after_id'] = max($summary['last_after_id'], (int) $result['legacy_id']);
                    } catch (\Throwable $e) {
                        $summary['errors'][] = [
                            'legacy_id' => (int) ($record['legacy_id'] ?? 0),
                            'message' => $e->getMessage(),
                        ];
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

            $this->setSuccess(
                $summary['errors'] === [] ? 'Importacao temporaria de servicos concluida.' : 'Importacao temporaria de servicos concluida com falhas.',
                $summary,
                $summary['errors'] === [] ? 200 : 207,
            );

            return $summary;
        } catch (\Throwable $e) {
            Log::error(__METHOD__ . '@' . __LINE__, ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->setError('Falha ao importar servicos da API de migracao.', [$e->getMessage()], 500);
            return null;
        }
    }

    private function importRecord(int $companyId, int $userId, array $record): array
    {
        $normalized = $this->normalizeRecord($record);

        return DB::transaction(function () use ($companyId, $userId, $record, $normalized): array {
            $link = TemporaryServiceMigrationLink::query()
                ->where('company_id', $companyId)
                ->where('legacy_id', $normalized['legacy_id'])
                ->first();

            $service = $this->resolveService($companyId, $link, $normalized);
            $created = false;
            $updated = false;
            $softDeleted = false;
            $restored = false;

            if (! $service) {
                $service = new Service();
                $service->created_by = $userId;
                $created = true;
            } else {
                $updated = true;
            }

            $service->forceFill([
                'company_id' => $companyId,
                'service_code' => sprintf('LEG-%d', $normalized['legacy_id']),
                'name' => $normalized['name'],
                'description' => $normalized['description'],
                'price' => $normalized['price'],
                'min_sale_price' => $normalized['price'],
                'cost' => 0,
                'category' => 'migrado',
                'is_active' => $normalized['is_active'],
                'requires_approval' => false,
                'accept_customer_discount' => true,
                'tax_classification' => $normalized['tax_classification'],
                'additional_info' => [
                    'migration' => [
                        'legacy_id' => $normalized['legacy_id'],
                        'legacy_imposto_servico_id' => $normalized['legacy_imposto_servico_id'],
                    ],
                ],
                'updated_by' => $userId,
            ])->saveQuietly();

            if ($normalized['deleted_at'] !== null) {
                if (! $service->trashed()) {
                    Service::withoutEvents(fn () => $service->delete());
                    $softDeleted = true;
                }

                $service->forceFill(['deleted_at' => $normalized['deleted_at']])->saveQuietly();
            } elseif ($service->trashed()) {
                Service::withoutEvents(fn () => $service->restore());
                $restored = true;
            }

            TemporaryServiceMigrationLink::query()->updateOrCreate(
                ['company_id' => $companyId, 'legacy_id' => $normalized['legacy_id']],
                [
                    'service_id' => $service->id,
                    'legacy_updated_at' => $normalized['updated_at'],
                    'legacy_deleted_at' => $normalized['deleted_at'],
                    'payload' => $record,
                    'last_imported_at' => now(),
                ]
            );

            return [
                'legacy_id' => $normalized['legacy_id'],
                'service_created' => $created,
                'service_updated' => $updated,
                'soft_deleted' => $softDeleted,
                'restored' => $restored,
            ];
        });
    }

    private function resolveService(int $companyId, ?TemporaryServiceMigrationLink $link, array $normalized): ?Service
    {
        if ($link?->service_id) {
            $service = Service::withTrashed()->find($link->service_id);
            if ($service) {
                return $service;
            }
        }

        return Service::withTrashed()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($normalized) {
                $query->where('service_code', sprintf('LEG-%d', $normalized['legacy_id']))
                    ->orWhere('name', $normalized['name']);
            })
            ->first();
    }

    private function normalizeRecord(array $record): array
    {
        $legacyId = (int) ($record['legacy_id'] ?? 0);

        if ($legacyId <= 0) {
            throw new \InvalidArgumentException('Registro de servico sem legacy_id valido.');
        }

        $name = trim((string) ($record['nome'] ?? ''));

        if ($name === '') {
            throw new \InvalidArgumentException('Servico legado sem nome.');
        }

        return [
            'legacy_id' => $legacyId,
            'name' => mb_substr($name, 0, 255),
            'description' => ($description = trim((string) ($record['descricao'] ?? ''))) === '' ? null : mb_substr($description, 0, 2000),
            'price' => (float) ($record['valor_unitario'] ?? 0),
            'is_active' => (bool) ($record['ativo'] ?? true),
            'legacy_imposto_servico_id' => $record['imposto_servico_id'] ?? null,
            'tax_classification' => isset($record['imposto_servico_id']) ? (string) $record['imposto_servico_id'] : null,
            'updated_at' => $this->parseDateTime($record['updated_at'] ?? null),
            'deleted_at' => $this->parseDateTime($record['deleted_at'] ?? null),
        ];
    }

    private function parseDateTime(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
