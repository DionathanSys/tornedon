<?php

namespace App\Services\Cnpj\Providers;

use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;
use App\Services\Cnpj\DTO\CnpjProviderResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BrasilApiProvider implements CnpjApiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://brasilapi.com.br/api/cnpj/v1';

    private const DEFAULT_TIMEOUT = 15;

    public function __construct(
        private readonly array $config = [],
    ) {}

    public function name(): string
    {
        return 'brasil_api';
    }

    public function consult(string $cnpj): CnpjProviderResult
    {
        $configuredBaseUrl = (string) ($this->config['base_url'] ?? self::DEFAULT_BASE_URL);

        $baseUrl = rtrim($configuredBaseUrl, '/');
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
                Log::error('Limite de requisicoes da API atingido.', ['response' => $response->body()]);

                return CnpjProviderResult::failure(
                    'Limite de requisicoes da API atingido.',
                    [$this->extractResponseError($response->body())],
                    429,
                );
            }

            if ($response->failed()) {
                return CnpjProviderResult::failure(
                    $this->buildHttpFailureMessage('BrasilAPI', $response->status()),
                    [$this->extractResponseError($response->body())],
                    $response->status(),
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                Log::error('Resposta invalida da API de CNPJ', ['response' => $data]);

                return CnpjProviderResult::failure(
                    'Resposta invalida da API de CNPJ.',
                    [$this->extractResponseError($response->body())],
                    502,
                );
            }

            return CnpjProviderResult::success(
                $this->normalizePayload($data, $cnpj),
                ['raw_response' => $data],
            );
        } catch (\Throwable $e) {
            Log::error('Erro ao consultar CNPJ', ['exception' => $e->getMessage()]);

            return CnpjProviderResult::failure(
                'Erro ao consultar CNPJ',
                [$e->getMessage()],
                500,
            );
        }
    }

    private function normalizePayload(array $data, string $fallbackCnpj): array
    {
        $taxId = preg_replace('/\D/', '', (string) ($data['cnpj'] ?? $fallbackCnpj));
        $phone = $this->normalizePhone((string) ($data['ddd_telefone_1'] ?? ''));
        $email = $data['email'] ?? null;

        return [
            'taxId' => $taxId,
            'company' => [
                'name' => (string) ($data['razao_social'] ?? ''),
                'nature' => [
                    'text' => $data['natureza_juridica'] ?? null,
                ],
                'equity' => (float) ($data['capital_social'] ?? 0),
                'simples' => [
                    'optant' => (bool) ($data['opcao_pelo_simples'] ?? false),
                ],
                'simei' => [
                    'optant' => (bool) ($data['opcao_pelo_mei'] ?? false),
                ],
            ],
            'alias' => $data['nome_fantasia'] ?? null,
            'founded' => $data['data_inicio_atividade'] ?? null,
            'head' => (int) ($data['identificador_matriz_filial'] ?? 0) === 1,
            'status' => [
                'text' => $data['descricao_situacao_cadastral'] ?? null,
            ],
            'statusDate' => $data['data_situacao_cadastral'] ?? null,
            'address' => [
                'street' => trim((string) ($data['logradouro'] ?? '')),
                'number' => $data['numero'] ?? null,
                'district' => $data['bairro'] ?? null,
                'city' => $data['municipio'] ?? null,
                'state' => $data['uf'] ?? null,
                'zip' => preg_replace('/\D/', '', (string) ($data['cep'] ?? '')),
                'details' => $data['complemento'] ?? null,
                'municipality' => $data['codigo_municipio_ibge'] ?? null,
            ],
            'mainActivity' => $this->normalizeMainActivity($data),
            'sideActivities' => $this->normalizeSideActivities($data['cnaes_secundarios'] ?? []),
            'registrations' => $this->normalizeRegistrations($data),
            'phones' => $phone ? [$phone] : [],
            'emails' => $email ? [['address' => $email]] : [],
        ];
    }

    private function normalizeMainActivity(array $data): ?array
    {
        if (! isset($data['cnae_fiscal'])) {
            return null;
        }

        return [
            'id' => (int) preg_replace('/\D/', '', (string) $data['cnae_fiscal']),
            'text' => (string) ($data['cnae_fiscal_descricao'] ?? ''),
        ];
    }

    private function normalizeSideActivities(array $activities): array
    {
        $normalized = [];

        foreach ($activities as $activity) {
            if (! is_array($activity)) {
                continue;
            }

            $code = preg_replace('/\D/', '', (string) ($activity['codigo'] ?? ''));
            $text = trim((string) ($activity['descricao'] ?? ''));

            if ($code === '' || $text === '') {
                continue;
            }

            $normalized[] = [
                'id' => (int) $code,
                'text' => $text,
            ];
        }

        return $normalized;
    }

    private function normalizeRegistrations(array $data): array
    {
        $registrations = [];

        if (! empty($data['inscricoes_estaduais']) && is_array($data['inscricoes_estaduais'])) {
            foreach ($data['inscricoes_estaduais'] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $number = preg_replace('/\D/', '', (string) ($item['inscricao_estadual'] ?? ''));

                if ($number === '') {
                    continue;
                }

                $registrations[] = [
                    'number' => $number,
                    'state' => $item['uf'] ?? ($data['uf'] ?? ''),
                    'enabled' => true,
                    'status' => ['text' => $item['situacao'] ?? 'ATIVA'],
                    'type' => ['text' => 'Estadual'],
                ];
            }
        }

        if ($registrations === [] && ! empty($data['inscricao_estadual'])) {
            $number = preg_replace('/\D/', '', (string) $data['inscricao_estadual']);

            if ($number !== '') {
                $registrations[] = [
                    'number' => $number,
                    'state' => $data['uf'] ?? '',
                    'enabled' => true,
                    'status' => ['text' => 'ATIVA'],
                    'type' => ['text' => 'Estadual'],
                ];
            }
        }

        return $registrations;
    }

    private function normalizePhone(string $phone): ?array
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) < 10) {
            return null;
        }

        return [
            'area' => substr($digits, 0, 2),
            'number' => substr($digits, 2),
        ];
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
