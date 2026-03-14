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
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($headers)
                ->get($url);

            if ($response->status() === 429) {
                return CnpjProviderResult::failure(
                    'Limite de requisicoes da API atingido.',
                    [$response->body()],
                    429,
                );
            }

            if ($response->failed()) {
                return CnpjProviderResult::failure(
                    "Erro ao consultar CNPJ na API. Status: {$response->status()}",
                    [$response->body()],
                    $response->status(),
                );
            }

            $data = $response->json();

            Log::info('Resposta da API de CNPJ', ['response' => $data]);

            if (! is_array($data)) {
                Log::error('Resposta invalida da API de CNPJ', ['response' => $data]);
                return CnpjProviderResult::failure(
                    'Resposta invalida da API de CNPJ.',
                    [$response->body()],
                    502,
                );
            }

            return CnpjProviderResult::success($data);
        } catch (\Throwable $e) {
            Log::error('Erro ao consultar CNPJ', ['exception' => $e->getMessage()]);
            return CnpjProviderResult::failure(
                'Erro ao consultar CNPJ',
                [$e->getMessage()],
                500,
            );
        }
    }
}
