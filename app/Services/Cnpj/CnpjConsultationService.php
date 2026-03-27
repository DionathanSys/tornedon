<?php

namespace App\Services\Cnpj;

use App\Domain\DTO\Cnpj\CnpjVO;
use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;
use App\Services\Cnpj\DTO\CnpjProviderResult;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CnpjConsultationService
{
    use HandlesServiceResponse;

    private const DEFAULT_CACHE_TTL = 604800;
    private const DEFAULT_RATE_LIMIT_MAX_ATTEMPTS = 5;
    private const DEFAULT_RATE_LIMIT_DECAY_SECONDS = 60;

    /**
     * Consulta CNPJ com fallback entre provedores e cache local.
     */
    public function consult(string $cnpj): ?CnpjVO
    {
        $this->resetResponse();

        $cnpj = $this->sanitize($cnpj);

        if (! $this->isValid($cnpj)) {
            $this->setError('CNPJ invalido. Informe 14 digitos numericos.');
            Log::warning($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'message' => $this->getMessage(),
                'cnpj' => $cnpj,
            ]);
            return null;
        }

        $cached = $this->getFromCache($cnpj);

        if ($cached instanceof CnpjVO) {
            $this->setSuccess('Consulta CNPJ realizada com sucesso (cache)');
            return $cached;
        }

        $providers = $this->resolveProviders();

        if ($providers === []) {
            $this->setError('Nenhum provedor de consulta de CNPJ configurado.', [], 500);
            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'cnpj' => $cnpj,
            ]);
            return null;
        }

        $lastFailure = null;

        foreach ($providers as $provider) {
            $providerName = $provider->name();

            if (! $this->checkRateLimit($providerName)) {
                $lastFailure = CnpjProviderResult::failure(
                    'Limite de consultas locais atingido para o provedor de CNPJ.',
                    [],
                    429,
                );

                Log::warning($lastFailure->message, [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'cnpj' => $cnpj,
                    'provider' => $providerName,
                ]);

                continue;
            }

            $result = $provider->consult($cnpj);

            if ($result->isSuccess()) {
                return $this->handleProviderSuccess($cnpj, $providerName, $result);
            }

            Log::warning('Falha ao consultar CNPJ no provedor', [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'cnpj' => $cnpj,
                'provider' => $providerName,
                'status' => $result->status,
                'message' => $result->message,
            ]);

            $lastFailure = $result;
        }

        $this->setError(
            $lastFailure?->message ?? 'Nao foi possivel consultar o CNPJ em nenhum provedor.',
            $lastFailure?->errors ?? [],
            $lastFailure?->status ?? 503,
        );

        return null;
    }

    /**
     * Retorna o VO do cache sem consultar API.
     */
    public function getFromCache(string $cnpj): ?CnpjVO
    {
        $cacheKey = $this->buildCacheKey($cnpj);

        $data = Cache::get($cacheKey);

        if ($data === null) {
            return null;
        }

        return CnpjVO::fromApiResponse($data);
    }

    /**
     * Invalida o cache para um CNPJ especifico.
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

    private function sanitize(string $cnpj): string
    {
        return str_replace(['.', '-', '/'], '', $cnpj);
    }

    private function isValid(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14) {
            return false;
        }

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

        $firstDigit = $calcDigit($cnpj, 12);
        $secondDigit = $calcDigit($cnpj, 13);

        return (int) $cnpj[12] === $firstDigit
            && (int) $cnpj[13] === $secondDigit;
    }

    private function checkRateLimit(string $providerName): bool
    {
        $maxAttempts = $this->getProviderRateLimitMaxAttempts($providerName);
        $decaySeconds = $this->getProviderRateLimitDecaySeconds($providerName);

        return RateLimiter::attempt(
            $this->buildRateLimiterKey($providerName),
            $maxAttempts,
            fn () => true,
            $decaySeconds,
        );
    }

    /**
     * Resolve e instancia os provedores configurados.
     *
     * @return array<int, CnpjApiProviderInterface>
     */
    private function resolveProviders(): array
    {
        $providerNames = config('cnpj.providers', ['brasil_api']);

        if (is_string($providerNames)) {
            $providerNames = array_values(array_filter(array_map(
                static fn (string $name): string => trim($name),
                explode(',', $providerNames),
            )));
        }

        $providerNames = is_array($providerNames) ? $providerNames : [];
        $providerClasses = config('cnpj.provider_classes', []);
        $providers = [];

        foreach ($providerNames as $providerName) {
            $providerClass = $providerClasses[$providerName] ?? null;

            if (! is_string($providerClass) || ! class_exists($providerClass)) {
                Log::error('Provider de CNPJ nao encontrado.', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'provider' => $providerName,
                    'class' => $providerClass,
                ]);
                continue;
            }

            try {
                $provider = app()->makeWith($providerClass, [
                    'config' => (array) config('cnpj.provider_settings.' . $providerName, []),
                ]);
            } catch (\Throwable $e) {
                Log::error('Falha ao instanciar provider de CNPJ.', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'provider' => $providerName,
                    'class' => $providerClass,
                    'exception' => $e->getMessage(),
                ]);
                continue;
            }

            if (! $provider instanceof CnpjApiProviderInterface) {
                Log::error('Classe do provider nao implementa o contrato esperado.', [
                    'metodo' => __METHOD__ . '@' . __LINE__,
                    'provider' => $providerName,
                    'class' => $providerClass,
                ]);
                continue;
            }

            $providers[] = $provider;
        }

        return $providers;
    }


    private function handleProviderSuccess(
        string $cnpj,
        string $providerName,
        CnpjProviderResult $result,
    ): ?CnpjVO {
        $data = $result->data ?? [];

        Cache::put($this->buildCacheKey($cnpj), $data, $this->getCacheTtl());

        try {
            $vo = CnpjVO::fromApiResponse($data);
        } catch (\Throwable $e) {
            $this->setError('Erro ao transformar dados do CNPJ.', [$e->getMessage()], 500);

            Log::error($this->getMessage(), [
                'metodo' => __METHOD__ . '@' . __LINE__,
                'provider' => $providerName,
                'cnpj' => $cnpj,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }

        $this->setSuccess("Consulta CNPJ realizada com sucesso ({$providerName})");

        $mainRegistration = $vo->getMainStateRegistration();

        Log::info($this->getMessage(), [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'provider' => $providerName,
            'cnpj' => $cnpj,
            'company_name' => $vo->companyName,
            'status_cnpj' => $vo->statusText,
            'city' => $vo->address->city,
            'state' => $vo->address->state,
            'registrations_count' => count($vo->registrations),
            'registrations' => array_map(fn($r) => [
                'number' => $r->number,
                'state' => $r->state,
                'enabled' => $r->enabled,
                'status' => $r->statusText,
            ], $vo->registrations),
            'main_registration' => $mainRegistration ? [
                'number' => $mainRegistration->number,
                'state' => $mainRegistration->state,
            ] : null,
        ]);

        return $vo;
    }

    private function buildCacheKey(string $cnpj): string
    {
        return "cnpj_consultation:{$cnpj}";
    }

    private function buildRateLimiterKey(string $providerName): string
    {
        return "cnpj-consultation:{$providerName}";
    }

    private function getCacheTtl(): int
    {
        return (int) config('cnpj.cache_ttl', self::DEFAULT_CACHE_TTL);
    }

    private function getRateLimitMaxAttempts(): int
    {
        return (int) config('cnpj.rate_limit.max_attempts', self::DEFAULT_RATE_LIMIT_MAX_ATTEMPTS);
    }

    private function getRateLimitDecaySeconds(): int
    {
        return (int) config('cnpj.rate_limit.decay_seconds', self::DEFAULT_RATE_LIMIT_DECAY_SECONDS);
    }

    private function getProviderRateLimitMaxAttempts(string $providerName): int
    {
        return (int) config(
            'cnpj.provider_settings.' . $providerName . '.rate_limit.max_attempts',
            $this->getRateLimitMaxAttempts(),
        );
    }

    private function getProviderRateLimitDecaySeconds(string $providerName): int
    {
        return (int) config(
            'cnpj.provider_settings.' . $providerName . '.rate_limit.decay_seconds',
            $this->getRateLimitDecaySeconds(),
        );
    }
}
