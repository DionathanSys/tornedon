<?php

namespace App\Services\Cnpj\Providers;

use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;
use App\Services\Cnpj\DTO\CnpjProviderResult;
use Illuminate\Support\Facades\Http;

class ReceitaWsProvider implements CnpjApiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://www.receitaws.com.br/v1/cnpj';
    private const DEFAULT_TIMEOUT = 20;

    public function __construct(
        private readonly array $config = [],
    ) {}

    public function name(): string
    {
        return 'receitaws';
    }

    public function consult(string $cnpj): CnpjProviderResult
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? self::DEFAULT_BASE_URL), '/');
        $timeout = (int) ($this->config['timeout'] ?? self::DEFAULT_TIMEOUT);
        $headers = (array) ($this->config['headers'] ?? []);
        $url = "{$baseUrl}/{$cnpj}";

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($headers)
                ->get($url);

            if ($response->status() === 429) {
                return CnpjProviderResult::failure(
                    'Limite de requisicoes da API ReceitaWS atingido.',
                    [$response->body()],
                    429,
                );
            }

            if ($response->failed()) {
                return CnpjProviderResult::failure(
                    "Erro ao consultar CNPJ na ReceitaWS. Status: {$response->status()}",
                    [$response->body()],
                    $response->status(),
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                return CnpjProviderResult::failure(
                    'Resposta invalida da API ReceitaWS.',
                    [$response->body()],
                    502,
                );
            }

            if (strtoupper((string) ($data['status'] ?? '')) !== 'OK') {
                return CnpjProviderResult::failure(
                    (string) ($data['message'] ?? 'Falha ao consultar CNPJ na ReceitaWS.'),
                    [$response->body()],
                    422,
                );
            }

            return CnpjProviderResult::success(
                $this->normalizePayload($data, $cnpj),
                ['raw_response' => $data],
            );
        } catch (\Throwable $e) {
            return CnpjProviderResult::failure(
                'Erro ao consultar CNPJ na ReceitaWS.',
                [$e->getMessage()],
                500,
            );
        }
    }

    private function normalizePayload(array $data, string $fallbackCnpj): array
    {
        $taxId = preg_replace('/\D/', '', (string) ($data['cnpj'] ?? $fallbackCnpj));

        return [
            'taxId' => $taxId,
            'company' => [
                'name' => (string) ($data['nome'] ?? ''),
                'nature' => [
                    'text' => $data['natureza_juridica'] ?? null,
                ],
                'equity' => $this->parseEquity($data['capital_social'] ?? null),
                'simples' => [
                    'optant' => (bool) ($data['simples']['optante'] ?? false),
                ],
                'simei' => [
                    'optant' => (bool) ($data['simei']['optante'] ?? false),
                ],
            ],
            'alias' => $data['fantasia'] ?? null,
            'founded' => $data['abertura'] ?? null,
            'head' => strtoupper((string) ($data['tipo'] ?? '')) === 'MATRIZ',
            'status' => [
                'text' => $data['situacao'] ?? null,
            ],
            'statusDate' => $data['data_situacao'] ?? null,
            'address' => [
                'street' => $data['logradouro'] ?? null,
                'number' => $data['numero'] ?? null,
                'district' => $data['bairro'] ?? null,
                'city' => $data['municipio'] ?? null,
                'state' => $data['uf'] ?? null,
                'zip' => preg_replace('/\D/', '', (string) ($data['cep'] ?? '')),
                'details' => $data['complemento'] ?? null,
                'municipality' => null,
            ],
            'mainActivity' => $this->normalizeMainActivity($data['atividade_principal'] ?? []),
            'sideActivities' => $this->normalizeActivities($data['atividades_secundarias'] ?? []),
            'registrations' => [],
            'phones' => $this->normalizePhones($data['telefone'] ?? null),
            'emails' => $this->normalizeEmails($data['email'] ?? null),
        ];
    }

    private function normalizeMainActivity(array $items): ?array
    {
        $all = $this->normalizeActivities($items);
        return $all[0] ?? null;
    }

    private function normalizeActivities(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $this->parseCnaeId($item['code'] ?? null);
            $text = (string) ($item['text'] ?? '');

            if ($id === null || $text === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'text' => $text,
            ];
        }

        return $normalized;
    }

    private function parseCnaeId(mixed $value): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        if ($digits === '') {
            return null;
        }

        return (int) $digits;
    }

    private function parseEquity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);

        if (! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function normalizePhones(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[\/,;|]+/', $value) ?: [];
        $phones = [];

        foreach ($parts as $part) {
            $digits = preg_replace('/\D/', '', $part);

            if (strlen($digits) < 10) {
                continue;
            }

            $area = substr($digits, 0, 2);
            $number = substr($digits, 2);

            $phones[] = [
                'area' => $area,
                'number' => $number,
            ];
        }

        return $phones;
    }

    private function normalizeEmails(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $parts = preg_split('/[\/,;|]+/', $value) ?: [];
        $emails = [];

        foreach ($parts as $part) {
            $email = trim($part);

            if ($email === '') {
                continue;
            }

            $emails[] = [
                'address' => $email,
            ];
        }

        return $emails;
    }
}
