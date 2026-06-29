<?php

namespace App\Services\Cnpj\Providers;

use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;
use App\Services\Cnpj\DTO\CnpjProviderResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenCnpjaProvider implements CnpjApiProviderInterface
{
    private const DEFAULT_BASE_URL = 'https://open.cnpja.com/office';

    private const DEFAULT_TIMEOUT = 15;

    public function __construct(
        private readonly array $config = [],
    ) {}

    public function name(): string
    {
        return 'open_cnpja';
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
                    'Limite de requisicoes da API OpenCnpja atingido.',
                    [$this->extractResponseError($response->body())],
                    429,
                );
            }

            if ($response->failed()) {
                return CnpjProviderResult::failure(
                    $this->buildHttpFailureMessage('OpenCnpja', $response->status()),
                    [$this->extractResponseError($response->body())],
                    $response->status(),
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                Log::error('Resposta invalida da API OpenCnpja', ['response' => $data]);

                return CnpjProviderResult::failure(
                    'Resposta invalida da API OpenCnpja.',
                    [$this->extractResponseError($response->body())],
                    502,
                );
            }

            return CnpjProviderResult::success($this->normalizePayload($data, $cnpj));
        } catch (\Throwable $e) {
            Log::error('Erro ao consultar CNPJ na OpenCnpja', ['exception' => $e->getMessage()]);

            return CnpjProviderResult::failure(
                'Erro ao consultar CNPJ na OpenCnpja.',
                [$e->getMessage()],
                500,
            );
        }
    }

    private function normalizePayload(array $data, string $fallbackCnpj): array
    {
        $taxId = preg_replace('/\D/', '', (string) ($data['taxId'] ?? $fallbackCnpj));

        return [
            'taxId' => $taxId,
            'company' => [
                'name' => (string) data_get($data, 'company.name', ''),
                'nature' => [
                    'text' => data_get($data, 'company.nature.text'),
                ],
                'equity' => data_get($data, 'company.equity'),
                'simples' => [
                    'optant' => (bool) data_get($data, 'company.simples.optant', false),
                ],
                'simei' => [
                    'optant' => (bool) data_get($data, 'company.simei.optant', false),
                ],
            ],
            'alias' => data_get($data, 'alias'),
            'founded' => data_get($data, 'founded'),
            'head' => (bool) data_get($data, 'head', false),
            'status' => [
                'text' => data_get($data, 'status.text'),
            ],
            'statusDate' => data_get($data, 'statusDate'),
            'address' => [
                'street' => data_get($data, 'address.street'),
                'number' => data_get($data, 'address.number'),
                'district' => data_get($data, 'address.district'),
                'city' => data_get($data, 'address.city'),
                'state' => data_get($data, 'address.state'),
                'zip' => preg_replace('/\D/', '', (string) data_get($data, 'address.zip', '')),
                'details' => data_get($data, 'address.details'),
                'municipality' => data_get($data, 'address.municipality'),
            ],
            'mainActivity' => $this->normalizeActivity(data_get($data, 'mainActivity')),
            'sideActivities' => $this->normalizeActivities(data_get($data, 'sideActivities', [])),
            'registrations' => $this->normalizeRegistrations($data),
            'phones' => $this->normalizePhones(data_get($data, 'phones', [])),
            'emails' => $this->normalizeEmails(data_get($data, 'emails', [])),
        ];
    }

    private function normalizeActivity(mixed $activity): ?array
    {
        if (! is_array($activity)) {
            return null;
        }

        $id = (int) preg_replace('/\D/', '', (string) ($activity['id'] ?? '0'));
        $text = trim((string) ($activity['text'] ?? ''));

        if ($id === 0 || $text === '') {
            return null;
        }

        return [
            'id' => $id,
            'text' => $text,
        ];
    }

    private function normalizeActivities(mixed $activities): array
    {
        if (! is_array($activities)) {
            return [];
        }

        $normalized = [];

        foreach ($activities as $activity) {
            $item = $this->normalizeActivity($activity);

            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    private function normalizeRegistrations(array $data): array
    {
        $registrations = [];

        foreach ((array) ($data['registrations'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $number = preg_replace('/\D/', '', (string) ($item['number'] ?? ''));

            if ($number === '') {
                continue;
            }

            $registrations[] = [
                'number' => $number,
                'state' => (string) ($item['state'] ?? ''),
                'enabled' => (bool) ($item['enabled'] ?? false),
                'status' => ['text' => data_get($item, 'status.text')],
                'type' => ['text' => data_get($item, 'type.text', 'Estadual')],
            ];
        }

        return $registrations;
    }

    private function normalizePhones(mixed $phones): array
    {
        if (! is_array($phones)) {
            return [];
        }

        $normalized = [];

        foreach ($phones as $phone) {
            if (! is_array($phone)) {
                continue;
            }

            $area = preg_replace('/\D/', '', (string) ($phone['area'] ?? ''));
            $number = preg_replace('/\D/', '', (string) ($phone['number'] ?? ''));

            if (strlen($area) !== 2 || strlen($number) < 8) {
                continue;
            }

            $normalized[] = [
                'area' => $area,
                'number' => $number,
            ];
        }

        return $normalized;
    }

    private function normalizeEmails(mixed $emails): array
    {
        if (! is_array($emails)) {
            return [];
        }

        $normalized = [];

        foreach ($emails as $email) {
            if (! is_array($email)) {
                continue;
            }

            $address = trim((string) ($email['address'] ?? ''));

            if ($address === '') {
                continue;
            }

            $normalized[] = [
                'address' => $address,
            ];
        }

        return $normalized;
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
