<?php

namespace App\Services\TemporaryMigration;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PartnerMigrationApiClient
{
    public function fetchPage(array $filters = []): array
    {
        $baseUrl = rtrim((string) config('services.migration_api.base_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('MIGRATION_API_BASE_URL nao configurada.');
        }

        $request = Http::acceptJson()
            ->timeout((int) config('services.migration_api.timeout', 30))
            ->baseUrl($baseUrl);

        $key = (string) config('services.migration_api.key', '');

        if ($key !== '') {
            $request = $request->withHeaders([
                'X-Migration-Key' => $key,
            ]);
        }

        $response = $request->get('/api/migracao/parceiros', array_filter([
            'limit' => $filters['limit'] ?? 500,
            'after_id' => $filters['after_id'] ?? null,
            'updated_from' => $filters['updated_from'] ?? null,
            'include_deleted' => ($filters['include_deleted'] ?? false) ? 1 : 0,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        Log::debug('Consultando API de migracao de parceiros', [
            'filters' => $filters,
            'base_url' => $baseUrl,
            'response_status' => $response->status(),
            'response_body' => $response->body(),
        ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Falha ao consultar API de migracao de parceiros. HTTP %s. Resposta: %s',
                $response->status(),
                $response->body(),
            ));
        }

        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload['data']) || ! isset($payload['meta'])) {
            throw new RuntimeException('Resposta invalida da API de migracao de parceiros.');
        }

        return $payload;
    }
}
