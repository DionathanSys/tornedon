<?php

namespace App\Services\Cnpj\Providers;

use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;
use App\Services\Cnpj\DTO\CnpjProviderResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CnpjWsProvider implements CnpjApiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://publica.cnpj.ws/cnpj';

    private const DEFAULT_TIMEOUT = 15;

    public function __construct(
        private readonly array $config = [],
    ) {}

    public function name(): string
    {
        return 'cnpj_ws';
    }

    public function consult(string $cnpj): CnpjProviderResult
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $timeout = (int) ($this->config['timeout'] ?? self::DEFAULT_TIMEOUT);
        $headers = (array) ($this->config['headers'] ?? []);
        $url = "{$baseUrl}/{$cnpj}";

        try {
            $request = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($headers);

            if (app()->environment('local')) {
                $request->withoutVerifying();
            }

            $response = $request->get($url);

            if ($response->status() === 429) {
                return CnpjProviderResult::failure(
                    'Limite de requisicoes da API CNPJ.ws atingido.',
                    [$this->extractResponseError($response->body())],
                    429,
                );
            }

            if ($response->failed()) {
                return CnpjProviderResult::failure(
                    $this->buildHttpFailureMessage('CNPJ.ws', $response->status()),
                    [$this->extractResponseError($response->body())],
                    $response->status(),
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                Log::error('Resposta invalida da API CNPJ.ws', ['response' => $data]);

                return CnpjProviderResult::failure(
                    'Resposta invalida da API CNPJ.ws.',
                    [$this->extractResponseError($response->body())],
                    502,
                );
            }

            return CnpjProviderResult::success(
                $this->normalizePayload($data, $cnpj),
                ['raw_response' => $data],
            );
        } catch (\Throwable $e) {
            Log::error('Erro ao consultar CNPJ na CNPJ.ws', ['exception' => $e->getMessage()]);

            return CnpjProviderResult::failure(
                'Erro ao consultar CNPJ na CNPJ.ws.',
                [$e->getMessage()],
                500,
            );
        }
    }

    private function normalizePayload(array $data, string $fallbackCnpj): array
    {
        $establishment = (array) ($data['estabelecimento'] ?? []);
        $phone = $this->normalizePhone(
            (string) ($establishment['ddd1'] ?? ''),
            (string) ($establishment['telefone1'] ?? ''),
        );

        return [
            'taxId' => preg_replace('/\D/', '', (string) ($establishment['cnpj'] ?? $fallbackCnpj)),
            'company' => [
                'name' => (string) ($data['razao_social'] ?? ''),
                'nature' => [
                    'text' => data_get($data, 'natureza_juridica.descricao'),
                ],
                'equity' => $this->parseEquity($data['capital_social'] ?? null),
                'simples' => [
                    'optant' => $this->toBoolean(data_get($data, 'simples.simples')),
                ],
                'simei' => [
                    'optant' => $this->toBoolean(data_get($data, 'simples.mei')),
                ],
            ],
            'alias' => $establishment['nome_fantasia'] ?? null,
            'founded' => $establishment['data_inicio_atividade'] ?? null,
            'head' => strtoupper((string) ($establishment['tipo'] ?? '')) === 'MATRIZ',
            'status' => [
                'text' => $establishment['situacao_cadastral'] ?? null,
            ],
            'statusDate' => $establishment['data_situacao_cadastral'] ?? null,
            'address' => [
                'street' => trim(implode(' ', array_filter([
                    $establishment['tipo_logradouro'] ?? null,
                    $establishment['logradouro'] ?? null,
                ]))),
                'number' => $establishment['numero'] ?? null,
                'district' => $establishment['bairro'] ?? null,
                'city' => data_get($establishment, 'cidade.nome'),
                'state' => data_get($establishment, 'estado.sigla'),
                'zip' => preg_replace('/\D/', '', (string) ($establishment['cep'] ?? '')),
                'details' => $establishment['complemento'] ?? null,
                'municipality' => data_get($establishment, 'cidade.ibge_id'),
            ],
            'mainActivity' => $this->normalizeActivity($establishment['atividade_principal'] ?? null),
            'sideActivities' => $this->normalizeActivities($establishment['atividades_secundarias'] ?? []),
            'registrations' => $this->normalizeRegistrations($establishment['inscricoes_estaduais'] ?? []),
            'phones' => $phone ? [$phone] : [],
            'emails' => filled($establishment['email'] ?? null) ? [['address' => (string) $establishment['email']]] : [],
        ];
    }

    private function normalizeActivity(mixed $activity): ?array
    {
        if (! is_array($activity)) {
            return null;
        }

        $id = preg_replace('/\D/', '', (string) ($activity['id'] ?? ''));
        $text = trim((string) ($activity['descricao'] ?? ''));

        if ($id === '' || $text === '') {
            return null;
        }

        return [
            'id' => (int) $id,
            'text' => $text,
        ];
    }

    private function normalizeActivities(array $activities): array
    {
        $normalized = [];

        foreach ($activities as $activity) {
            $item = $this->normalizeActivity($activity);

            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function normalizeRegistrations(array $registrations): array
    {
        $normalized = [];

        foreach ($registrations as $registration) {
            if (! is_array($registration)) {
                continue;
            }

            $number = preg_replace('/\D/', '', (string) ($registration['inscricao_estadual'] ?? ''));

            if ($number === '') {
                continue;
            }

            $normalized[] = [
                'number' => $number,
                'state' => (string) data_get($registration, 'estado.sigla', ''),
                'enabled' => (bool) ($registration['ativo'] ?? false),
                'status' => ['text' => (bool) ($registration['ativo'] ?? false) ? 'ATIVA' : 'INATIVA'],
                'type' => ['text' => 'Estadual'],
            ];
        }

        return $normalized;
    }

    private function normalizePhone(string $area, string $number): ?array
    {
        $area = preg_replace('/\D/', '', $area);
        $number = preg_replace('/\D/', '', $number);

        if (strlen($area) !== 2 || strlen($number) < 8) {
            return null;
        }

        return [
            'area' => $area,
            'number' => $number,
        ];
    }

    private function parseEquity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);

        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(mb_strtolower(trim((string) $value)), ['sim', 's', 'true', '1'], true);
    }

    private function buildHttpFailureMessage(string $providerLabel, int $status): string
    {
        if ($status >= 500) {
            return "O provider {$providerLabel} esta indisponivel no momento. Status: {$status}";
        }

        return "Erro ao consultar CNPJ na {$providerLabel}. Status: {$status}";
    }

    private function extractResponseError(string $body): string
    {
        $normalized = trim(strip_tags($body));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        if ($normalized === '') {
            return 'Resposta vazia do provider.';
        }

        return mb_substr($normalized, 0, 1000);
    }
}
