<?php

namespace App\Services\TemporaryMigration;

use App\Models\Company;
use App\Models\Contact;
use App\Models\TemporaryContactMigrationLink;
use App\Models\TemporaryPartnerMigrationLink;
use App\Models\User;
use App\Traits\HandlesServiceResponse;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TemporaryContactImportService
{
    use HandlesServiceResponse;

    public function __construct(private ContactMigrationApiClient $client) {}

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
            'contacts_created' => 0,
            'contacts_updated' => 0,
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
                        $summary['contacts_created'] += (int) $result['contact_created'];
                        $summary['contacts_updated'] += (int) $result['contact_updated'];
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
                $summary['errors'] === [] ? 'Importacao temporaria de contatos concluida.' : 'Importacao temporaria de contatos concluida com falhas.',
                $summary,
                $summary['errors'] === [] ? 200 : 207,
            );

            return $summary;
        } catch (\Throwable $e) {
            Log::error(__METHOD__ . '@' . __LINE__, ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->setError('Falha ao importar contatos da API de migracao.', [$e->getMessage()], 500);
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
            $link = TemporaryContactMigrationLink::query()
                ->where('company_id', $companyId)
                ->where('legacy_id', $normalized['legacy_id'])
                ->first();

            $contact = $this->resolveContact($link, $normalized, (int) $partnerLink->company_partner_id);
            $created = false;
            $updated = false;

            if (! $contact) {
                $contact = new Contact();
                $contact->company_partner_id = (int) $partnerLink->company_partner_id;
                $contact->created_by = $userId;
                $created = true;
            } else {
                $updated = true;
            }

            $before = $updated ? $contact->only([
                'company_partner_id',
                'email',
                'phone',
                'mobile',
                'notify',
                'is_active',
            ]) : null;

            $contact->forceFill([
                'company_partner_id' => (int) $partnerLink->company_partner_id,
                'email' => $normalized['email'],
                'phone' => $normalized['phone'],
                'mobile' => $normalized['mobile'],
                'notify' => $normalized['notify'],
                'is_active' => true,
                'updated_by' => $userId,
            ])->saveQuietly();

            TemporaryContactMigrationLink::query()->updateOrCreate(
                ['company_id' => $companyId, 'legacy_id' => $normalized['legacy_id']],
                [
                    'legacy_partner_id' => $normalized['legacy_partner_id'],
                    'contact_id' => $contact->id,
                    'company_partner_id' => (int) $partnerLink->company_partner_id,
                    'legacy_contact_name' => $normalized['legacy_contact_name'],
                    'fingerprint' => $normalized['fingerprint'],
                    'legacy_updated_at' => $normalized['updated_at'],
                    'payload' => $record,
                    'last_imported_at' => now(),
                ]
            );

            $after = $updated ? $contact->only([
                'company_partner_id',
                'email',
                'phone',
                'mobile',
                'notify',
                'is_active',
            ]) : null;

            if ($updated && $before !== $after) {
                Log::info(__METHOD__ . '@' . __LINE__, [
                    'message' => 'Contato atualizado durante importacao temporaria',
                    'company_id' => $companyId,
                    'user_id' => $userId,
                    'legacy_id' => $normalized['legacy_id'],
                    'legacy_partner_id' => $normalized['legacy_partner_id'],
                    'contact_id' => $contact->id,
                    'company_partner_id' => $contact->company_partner_id,
                    'legacy_contact_name' => $normalized['legacy_contact_name'],
                    'before' => $before,
                    'after' => $after,
                ]);
            }

            return ['legacy_id' => $normalized['legacy_id'], 'contact_created' => $created, 'contact_updated' => $updated];
        });
    }

    private function resolveContact(?TemporaryContactMigrationLink $link, array $normalized, int $companyPartnerId): ?Contact
    {
        if ($link?->contact_id) {
            $contact = Contact::query()->find($link->contact_id);
            if ($contact) {
                return $contact;
            }
        }

        if ($normalized['email'] !== null) {
            return Contact::query()
                ->where('company_partner_id', $companyPartnerId)
                ->where('email', $normalized['email'])
                ->first();
        }

        return Contact::query()
            ->where('company_partner_id', $companyPartnerId)
            ->where('phone', $normalized['phone'])
            ->where('mobile', $normalized['mobile'])
            ->first();
    }

    private function normalizeRecord(array $record): array
    {
        $legacyId = (int) ($record['legacy_id'] ?? 0);
        $legacyPartnerId = (int) ($record['legacy_parceiro_id'] ?? 0);

        if ($legacyId <= 0 || $legacyPartnerId <= 0) {
            throw new \InvalidArgumentException('Registro de contato sem chaves legadas validas.');
        }

        $email = $this->normalizeEmail($record['email'] ?? null);
        $phone = $this->normalizePhone($record['telefone_fixo'] ?? null);
        $mobile = $this->normalizePhone($record['telefone_cel'] ?? null);

        if ($email === null && $phone === null && $mobile === null) {
            throw new \InvalidArgumentException('Contato legado sem email ou telefone aproveitavel.');
        }

        return [
            'legacy_id' => $legacyId,
            'legacy_partner_id' => $legacyPartnerId,
            'legacy_contact_name' => $this->limit($record['nome_contato'] ?? null, 255),
            'email' => $email,
            'phone' => $phone,
            'mobile' => $mobile,
            'notify' => (bool) ($record['envio_ordem'] ?? false),
            'updated_at' => $this->parseDateTime($record['updated_at'] ?? null),
            'fingerprint' => sha1(json_encode([$email, $phone, $mobile], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = trim(mb_strtolower((string) $value));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function normalizePhone(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return $digits === '' ? null : $digits;
    }

    private function limit(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function parseDateTime(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
