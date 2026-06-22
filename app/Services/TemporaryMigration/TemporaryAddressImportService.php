<?php

namespace App\Services\TemporaryMigration;

use App\Models\Address;
use App\Models\Company;
use App\Models\TemporaryAddressMigrationLink;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\User;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TemporaryAddressImportService
{
    use HandlesServiceResponse;

    public function __construct(private AddressMigrationApiClient $client) {}

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
            'addresses_created' => 0,
            'addresses_updated' => 0,
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
                    'parceiro_id' => $filters['parceiro_id'] ?? null,
                ]);

                $summary['pages']++;

                foreach ($payload['data'] as $record) {
                    $summary['records_received']++;

                    try {
                        $result = $this->importRecord($companyId, $userId, $record);
                        $summary['addresses_created'] += (int) $result['address_created'];
                        $summary['addresses_updated'] += (int) $result['address_updated'];
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
                $summary['errors'] === [] ? 'Importacao temporaria de enderecos concluida.' : 'Importacao temporaria de enderecos concluida com falhas.',
                $summary,
                $summary['errors'] === [] ? 200 : 207,
            );

            return $summary;
        } catch (\Throwable $e) {
            Log::error(__METHOD__ . '@' . __LINE__, ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->setError('Falha ao importar enderecos da API de migracao.', [$e->getMessage()], 500);
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

        if (! $partnerLink?->company_partner_id) {
            throw new \RuntimeException(sprintf('Parceiro legado %s ainda nao foi importado para a empresa %s.', $normalized['legacy_partner_id'], $companyId));
        }

        return DB::transaction(function () use ($companyId, $userId, $record, $normalized, $partnerLink): array {
            $link = TemporaryAddressMigrationLink::query()
                ->where('company_id', $companyId)
                ->where('legacy_id', $normalized['legacy_id'])
                ->first();

            $address = $this->resolveAddress($link, $normalized, (int) $partnerLink->company_partner_id);
            $created = false;
            $updated = false;

            if (! $address) {
                $address = new Address();
                $address->company_partner_id = (int) $partnerLink->company_partner_id;
                $address->created_by = $userId;
                $created = true;
            } else {
                $updated = true;
            }

            $address->forceFill([
                'company_partner_id' => (int) $partnerLink->company_partner_id,
                'street' => $normalized['street'],
                'number' => $normalized['number'],
                'complement' => $normalized['complement'],
                'neighborhood' => $normalized['neighborhood'],
                'city' => $normalized['city'],
                'state' => $normalized['state'],
                'country' => $normalized['country'],
                'postal_code' => $normalized['postal_code'],
                'city_code' => $normalized['city_code'],
                'updated_by' => $userId,
            ])->saveQuietly();

            TemporaryAddressMigrationLink::query()->updateOrCreate(
                ['company_id' => $companyId, 'legacy_id' => $normalized['legacy_id']],
                [
                    'legacy_partner_id' => $normalized['legacy_partner_id'],
                    'address_id' => $address->id,
                    'company_partner_id' => (int) $partnerLink->company_partner_id,
                    'fingerprint' => $normalized['fingerprint'],
                    'legacy_updated_at' => $normalized['updated_at'],
                    'payload' => $record,
                    'last_imported_at' => now(),
                ]
            );

            return ['legacy_id' => $normalized['legacy_id'], 'address_created' => $created, 'address_updated' => $updated];
        });
    }

    private function resolveAddress(?TemporaryAddressMigrationLink $link, array $normalized, int $companyPartnerId): ?Address
    {
        if ($link?->address_id) {
            $address = Address::query()->find($link->address_id);
            if ($address) {
                return $address;
            }
        }

        return Address::query()
            ->where('company_partner_id', $companyPartnerId)
            ->where('street', $normalized['street'])
            ->where('number', $normalized['number'])
            ->where('postal_code', $normalized['postal_code'])
            ->first();
    }

    private function normalizeRecord(array $record): array
    {
        $legacyId = (int) ($record['legacy_id'] ?? 0);
        $legacyPartnerId = (int) ($record['legacy_parceiro_id'] ?? 0);

        if ($legacyId <= 0 || $legacyPartnerId <= 0) {
            throw new \InvalidArgumentException('Registro de endereco sem chaves legadas validas.');
        }

        $street = $this->limit($record['rua'] ?? null, 150);
        $number = $this->limit($record['numero'] ?? null, 20) ?? 'S/N';
        $city = $this->limit($record['cidade'] ?? null, 100);
        $state = Str::upper((string) $this->limit($record['estado'] ?? null, 2));
        $postalCode = $this->formatPostalCode($record['cep'] ?? null);

        if ($street === null || $city === null || $state === '' || $postalCode === null) {
            throw new \InvalidArgumentException('Endereco legado sem campos obrigatorios suficientes.');
        }

        return [
            'legacy_id' => $legacyId,
            'legacy_partner_id' => $legacyPartnerId,
            'street' => $street,
            'number' => $number,
            'complement' => $this->limit($record['complemento'] ?? null, 150),
            'neighborhood' => $this->limit($record['bairro'] ?? null, 100),
            'city' => $city,
            'state' => $state,
            'country' => $this->limit($record['pais'] ?? null, 50) ?? 'Brasil',
            'postal_code' => $postalCode,
            'city_code' => $this->digitsOrNull($record['codigo_municipio'] ?? null),
            'updated_at' => $this->parseDateTime($record['updated_at'] ?? null),
            'fingerprint' => sha1(json_encode([$street, $number, $city, $state, $postalCode], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function limit(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function digitsOrNull(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return $digits === '' ? null : $digits;
    }

    private function formatPostalCode(mixed $value): ?string
    {
        $digits = $this->digitsOrNull($value);
        if ($digits === null || strlen($digits) !== 8) {
            return null;
        }

        return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
    }

    private function parseDateTime(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
