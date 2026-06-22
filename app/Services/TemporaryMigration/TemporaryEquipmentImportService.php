<?php

namespace App\Services\TemporaryMigration;

use App\Enum\Equipment\Type as EquipmentType;
use App\Models\Company;
use App\Models\Equipment;
use App\Models\TemporaryEquipmentMigrationLink;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\User;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TemporaryEquipmentImportService
{
    use HandlesServiceResponse;

    public function __construct(
        private EquipmentMigrationApiClient $client,
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
            'equipments_created' => 0,
            'equipments_updated' => 0,
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
                    'parceiro_id' => $filters['parceiro_id'] ?? null,
                ]);

                $summary['pages']++;

                foreach ($payload['data'] as $record) {
                    $summary['records_received']++;

                    try {
                        $result = $this->importRecord($companyId, $userId, $record);
                        $summary['equipments_created'] += (int) $result['equipment_created'];
                        $summary['equipments_updated'] += (int) $result['equipment_updated'];
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
                            'message' => 'Falha ao importar equipamento da API de migracao',
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

            $message = $summary['errors'] === []
                ? 'Importacao temporaria de equipamentos concluida.'
                : 'Importacao temporaria de equipamentos concluida com falhas.';

            $this->setSuccess($message, $summary, $summary['errors'] === [] ? 200 : 207);

            return $summary;
        } catch (\Throwable $e) {
            $this->setError('Falha ao importar equipamentos da API de migracao.', [$e->getMessage()], 500);

            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Falha ao importar equipamentos da API de migracao',
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
        $partnerLink = TemporaryPartnerMigrationLink::query()
            ->where('company_id', $companyId)
            ->where('legacy_id', $normalized['legacy_partner_id'])
            ->first();

        if (! $partnerLink?->partner_id) {
            throw new \RuntimeException(sprintf(
                'Parceiro legado %s ainda nao foi importado para a empresa %s.',
                $normalized['legacy_partner_id'],
                $companyId,
            ));
        }

        return DB::transaction(function () use ($companyId, $userId, $record, $normalized, $partnerLink): array {
            $link = TemporaryEquipmentMigrationLink::query()
                ->where('company_id', $companyId)
                ->where('legacy_id', $normalized['legacy_id'])
                ->first();

            $equipment = $this->resolveEquipment($companyId, $link, $normalized, (int) $partnerLink->partner_id);
            $equipmentCreated = false;
            $equipmentUpdated = false;
            $softDeleted = false;
            $restored = false;

            if (! $equipment) {
                $equipment = new Equipment();
                $equipment->created_by = $userId;
                $equipmentCreated = true;
            } else {
                $equipmentUpdated = true;
            }

            $equipment->forceFill([
                'name' => $normalized['name'],
                'owner_id' => (int) $partnerLink->partner_id,
                'company_id' => $companyId,
                'type' => $normalized['type'],
                'placa' => null,
                'mark' => $normalized['mark'],
                'model' => $normalized['model'],
                'serial_number' => $normalized['serial_number'],
            ])->saveQuietly();

            if ($normalized['deleted_at'] !== null) {
                if (! $equipment->trashed()) {
                    Equipment::withoutEvents(fn () => $equipment->delete());
                    $softDeleted = true;
                }

                $equipment->forceFill([
                    'deleted_at' => $normalized['deleted_at'],
                ])->saveQuietly();
            } elseif ($equipment->trashed()) {
                Equipment::withoutEvents(fn () => $equipment->restore());
                $restored = true;
            }

            TemporaryEquipmentMigrationLink::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'legacy_id' => $normalized['legacy_id'],
                ],
                [
                    'legacy_partner_id' => $normalized['legacy_partner_id'],
                    'equipment_id' => $equipment->id,
                    'owner_partner_id' => (int) $partnerLink->partner_id,
                    'fingerprint' => $normalized['fingerprint'],
                    'legacy_updated_at' => $normalized['updated_at'],
                    'legacy_deleted_at' => $normalized['deleted_at'],
                    'payload' => $record,
                    'last_imported_at' => now(),
                ]
            );

            return [
                'legacy_id' => $normalized['legacy_id'],
                'equipment_created' => $equipmentCreated,
                'equipment_updated' => $equipmentUpdated,
                'soft_deleted' => $softDeleted,
                'restored' => $restored,
            ];
        });
    }

    private function resolveEquipment(
        int $companyId,
        ?TemporaryEquipmentMigrationLink $link,
        array $normalized,
        int $ownerPartnerId,
    ): ?Equipment {
        if ($link?->equipment_id) {
            $equipment = Equipment::withTrashed()->find($link->equipment_id);

            if ($equipment) {
                return $equipment;
            }
        }

        $query = Equipment::withTrashed()
            ->where('company_id', $companyId)
            ->where('owner_id', $ownerPartnerId)
            ->where('name', $normalized['name']);

        if ($normalized['serial_number'] !== null) {
            return $query->where('serial_number', $normalized['serial_number'])->first();
        }

        return $query
            ->where(function ($inner) use ($normalized) {
                $inner->where('mark', $normalized['mark'])
                    ->where('model', $normalized['model']);
            })
            ->first();
    }

    private function normalizeRecord(array $record): array
    {
        $legacyId = (int) ($record['legacy_id'] ?? 0);
        $legacyPartnerId = (int) ($record['legacy_parceiro_id'] ?? 0);

        if ($legacyId <= 0) {
            throw new \InvalidArgumentException('Registro sem legacy_id valido.');
        }

        if ($legacyPartnerId <= 0) {
            throw new \InvalidArgumentException('Registro sem legacy_parceiro_id valido.');
        }

        $name = $this->normalizeName($record['descricao'] ?? null, $legacyId);
        $serialNumber = $this->normalizeNullableString($record['nro_serie'] ?? null);
        $mark = $this->normalizeNullableString($record['marca'] ?? null);
        $model = $this->normalizeNullableString($record['modelo'] ?? null);

        return [
            'legacy_id' => $legacyId,
            'legacy_partner_id' => $legacyPartnerId,
            'name' => $name,
            'serial_number' => $serialNumber,
            'mark' => $mark,
            'model' => $model,
            'type' => $this->resolveEquipmentType($serialNumber, $mark, $model, $name),
            'updated_at' => $this->parseDateTime($record['updated_at'] ?? null),
            'deleted_at' => $this->parseDateTime($record['deleted_at'] ?? null),
            'fingerprint' => $this->buildFingerprint($legacyPartnerId, $name, $serialNumber, $mark, $model),
        ];
    }

    private function normalizeName(mixed $value, int $legacyId): string
    {
        $name = trim((string) $value);

        if ($name === '') {
            return 'Equipamento legado #' . $legacyId;
        }

        return mb_substr($name, 0, 255);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_substr($normalized, 0, 255);
    }

    private function resolveEquipmentType(?string $serialNumber, ?string $mark, ?string $model, string $name): string
    {
        $haystack = mb_strtoupper(trim(implode(' ', array_filter([$name, $mark, $model, $serialNumber]))));

        if (preg_match('/\b[A-Z]{3}[0-9][A-Z0-9][0-9]{2}\b/', $haystack) === 1) {
            return EquipmentType::CAR->value;
        }

        return str_contains($haystack, 'CAMINHAO') || str_contains($haystack, 'CAMINH')
            ? EquipmentType::TRUCK->value
            : EquipmentType::GENERAL_ELECTRONIC->value;
    }

    private function buildFingerprint(int $legacyPartnerId, string $name, ?string $serialNumber, ?string $mark, ?string $model): string
    {
        return sha1(json_encode([
            'legacy_partner_id' => $legacyPartnerId,
            'name' => $name,
            'serial_number' => $serialNumber,
            'mark' => $mark,
            'model' => $model,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function parseDateTime(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
