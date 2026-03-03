<?php

namespace App\Services\Cnpj;

use App\Domain\DTO\Cnpj\CnpjVO;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CnpjConsultationService
{
    use HandlesServiceResponse;

    private const API_BASE_URL = 'https://open.cnpja.com/office';

    /** Cache TTL: 7 dias (em segundos) */
    private const CACHE_TTL = 604800;

    /** Rate limit: máximo 5 consultas por minuto */
    private const RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const RATE_LIMIT_DECAY_SECONDS = 60;
    private const RATE_LIMITER_KEY = 'cnpj-consultation';

    /**
     * Consulta o CNPJ na API pública e retorna um Value Object com os dados.
     * Utiliza cache para evitar consultas repetidas e rate limiter para respeitar
     * o limite de 5 consultas por minuto.
     */
    public function consult(string $cnpj): ?CnpjVO
    {
        $cnpj = $this->sanitize($cnpj);

        if (! $this->isValid($cnpj)) {
            $this->setError('CNPJ inválido. Informe 14 dígitos numéricos.');
            Log::warning($this->getMessage(), [
                'metodo'    => __METHOD__ . '@' . __LINE__,
                'message'   => $this->getMessage(),
                'cnpj'      => $cnpj,
            ]);
            return null;
        }

        // Tenta buscar do cache primeiro
        $cached = $this->getFromCache($cnpj);

        if ($cached instanceof CnpjVO) {
            $this->setSuccess('Consulta CNPJ realizada com sucesso (cache)');
            return $cached;
        }

        // Verifica rate limit antes de chamar a API
        if (! $this->checkRateLimit()) {
            $this->setError(
                'Limite de consultas atingido. Aguarde um momento antes de tentar novamente.',
                [],
                429,
            );
            Log::warning($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'cnpj' => $cnpj,
            ]);
            return null;
        }

        return $this->fetchFromApi($cnpj);
    }

    /**
     * Retorna o VO do cache sem consultar a API.
     * Útil quando se deseja apenas recuperar dados já consultados.
     */
    public function getFromCache(string $cnpj): ?CnpjVO
    {
        $cnpj = $this->sanitize($cnpj);
        $cacheKey = $this->buildCacheKey($cnpj);

        $data = Cache::get($cacheKey);

        if ($data === null) {
            return null;
        }

        return CnpjVO::fromApiResponse($data);
    }

    /**
     * Invalida o cache para um CNPJ específico.
     */
    public function clearCache(string $cnpj): void
    {
        $cnpj = $this->sanitize($cnpj);
        Cache::forget($this->buildCacheKey($cnpj));

        Log::info(__METHOD__ . '@' . __LINE__, [
            'message' => 'Cache de consulta CNPJ invalidado',
            'cnpj' => $cnpj,
        ]);
    }

    /**
     * Sanitiza o CNPJ removendo caracteres não numéricos.
     */
    private function sanitize(string $cnpj): string
    {
        return preg_replace('/\D/', '', $cnpj);
    }

    /**
     * Valida se o CNPJ possui 14 dígitos e se os dígitos verificadores são válidos.
     */
    private function isValid(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14) {
            return false;
        }

        // Rejeita CNPJs com todos os dígitos iguais (ex: 00000000000000)
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $calcDigit = function (string $cnpj, int $length): int {
            $weights = $length === 12
                ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
                : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $cnpj[$i] * $weights[$i];
            }

            $remainder = $sum % 11;

            return $remainder < 2 ? 0 : 11 - $remainder;
        };

        $firstDigit  = $calcDigit($cnpj, 12);
        $secondDigit = $calcDigit($cnpj, 13);

        return (int) $cnpj[12] === $firstDigit
            && (int) $cnpj[13] === $secondDigit;
    }

    /**
     * Verifica se ainda há tentativas disponíveis no rate limiter.
     */
    private function checkRateLimit(): bool
    {
        return RateLimiter::attempt(
            self::RATE_LIMITER_KEY,
            self::RATE_LIMIT_MAX_ATTEMPTS,
            fn() => true,
            self::RATE_LIMIT_DECAY_SECONDS,
        );
    }

    /**
     * Consulta a API e armazena o resultado em cache.
     */
    private function fetchFromApi(string $cnpj): ?CnpjVO
    {
        $url = self::API_BASE_URL . '/' . $cnpj;

        Log::debug('Iniciando consulta CNPJ na API', [
            'metodo'                  => __METHOD__ . '@' . __LINE__,
            'url'                     => $url,
            'cnpj'                    => $cnpj,
            'rate_limiter_remaining'  => RateLimiter::remaining(self::RATE_LIMITER_KEY, self::RATE_LIMIT_MAX_ATTEMPTS),
        ]);

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::timeout(15)
                ->acceptJson()
                ->get($url);

            $statusCode      = $response->status();
            $responseHeaders = $response->headers();
            $responseBody    = $response->body();

            Log::debug('Resposta recebida da API de CNPJ', [
                'metodo'           => __METHOD__ . '@' . __LINE__,
                'cnpj'             => $cnpj,
                'url'              => $url,
                'status'           => $statusCode,
                'response_headers' => $responseHeaders,
                'response_body'    => $responseBody,
            ]);

            if ($statusCode === 429) {
                // Esgota o rate limiter interno para bloquear novas tentativas pelo período de decay
                RateLimiter::clear(self::RATE_LIMITER_KEY);

                $this->setError(
                    'Limite de requisições da API atingido. Aguarde 1 minuto antes de tentar novamente.',
                    [],
                    429,
                );

                Log::warning($this->getMessage(), [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'cnpj'             => $cnpj,
                    'retry_after'      => $responseHeaders['Retry-After'][0] ?? $responseHeaders['retry-after'][0] ?? null,
                    'x_ratelimit'      => array_filter($responseHeaders, fn($k) => str_starts_with(strtolower($k), 'x-ratelimit'), ARRAY_FILTER_USE_KEY),
                    'response_body'    => $responseBody,
                ]);

                return null;
            }

            if ($response->failed()) {
                $this->setError(
                    "Erro ao consultar CNPJ na API. Status: {$statusCode}",
                    [$responseBody],
                    $statusCode,
                );

                Log::error($this->getMessage(), [
                    'metodo'           => __METHOD__ . '@' . __LINE__,
                    'cnpj'             => $cnpj,
                    'url'              => $url,
                    'status'           => $statusCode,
                    'response_headers' => $responseHeaders,
                    'response_body'    => $responseBody,
                ]);

                return null;
            }

            $data = $response->json();

            // Armazena resposta original em cache
            Cache::put($this->buildCacheKey($cnpj), $data, self::CACHE_TTL);

            $vo = CnpjVO::fromApiResponse($data);

            $this->setSuccess('Consulta CNPJ realizada com sucesso');

            Log::info($this->getMessage(), [
                'metodo'       => __METHOD__ . '@' . __LINE__,
                'cnpj'         => $cnpj,
                'company_name' => $vo->companyName,
                'status_cnpj'  => $vo->statusText,
                'city'         => $vo->address->city,
                'state'        => $vo->address->state,
            ]);

            return $vo;
        } catch (\Exception $e) {
            $this->setError('Erro ao consultar CNPJ', [$e->getMessage()]);
            Log::error($this->getMessage(), [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'error_code' => $this->getErrorCode(),
                'cnpj'       => $cnpj,
                'url'        => $url,
                'exception'  => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace'      => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Gera a chave de cache para um CNPJ.
     */
    private function buildCacheKey(string $cnpj): string
    {
        return "cnpj_consultation:{$cnpj}";
    }
}
