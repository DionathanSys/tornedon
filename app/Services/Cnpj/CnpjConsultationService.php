<?php

namespace App\Services\Cnpj;

use App\Domain\DTO\Cnpj\CnpjVO;
use App\Services\Cnpj\DTO\CnpjProviderResult;
use App\Traits\HandlesServiceResponse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class CnpjConsultationService
{
    use HandlesServiceResponse;

    private const DEFAULT_CACHE_TTL = 604800;

    private const DEFAULT_RATE_LIMIT_MAX_ATTEMPTS = 5;

    private const DEFAULT_RATE_LIMIT_DECAY_SECONDS = 60;

    private const LOG_IDENTIFIER = 'cnpj_consultation';

    public function __construct(
        private readonly CnpjDocument $document,
        private readonly CnpjProviderRegistry $providerRegistry,
    ) {}

    /**
     * Consulta CNPJ com fallback entre provedores e cache local.
     */
    public function consult(string $cnpj, array $context = []): ?CnpjVO
    {
        $this->resetResponse();

        $cnpj = $this->document->sanitize($cnpj);
        $context = $this->resolveContext($context, $cnpj);

        if (! $this->document->isValid($cnpj)) {
            $this->setError('CNPJ inválido. Informe 14 digitos numéricos.');
            Log::warning($this->getMessage(), $this->buildLogContext('invalid_document', $context, [
                'metodo' => __METHOD__.'@'.__LINE__,
                'message' => $this->getMessage(),
            ]));

            return null;
        }

        $cached = $this->getFromCache($cnpj);

        if ($cached instanceof CnpjVO) {
            $this->setSuccess('Consulta CNPJ realizada com sucesso (cache)', [
                'provider' => 'cache',
                'cnpj' => $cnpj,
                'consultation_id' => $context['consultation_id'],
                'raw' => $cached->toArray(),
            ]);

            Log::info($this->getMessage(), $this->buildLogContext('success', $context, [
                'metodo' => __METHOD__.'@'.__LINE__,
                'provider' => 'cache',
                'status' => 'success',
            ]));

            return $cached;
        }

        $providers = $this->providerRegistry->resolveEnabled();

        if ($providers === []) {
            $this->setError('Nenhum provedor de consulta de CNPJ configurado.', [], 500);
            Log::error($this->getMessage(), $this->buildLogContext('no_provider_configured', $context, [
                'metodo' => __METHOD__.'@'.__LINE__,
            ]));

            return null;
        }

        $lastFailure = null;
        $lastProviderName = null;

        foreach ($providers as $resolvedProvider) {
            $providerName = $resolvedProvider->name;
            $lastProviderName = $providerName;

            if (! $this->checkRateLimit($resolvedProvider->config)) {
                $lastFailure = CnpjProviderResult::failure(
                    'Limite de consultas locais atingido para o provedor de CNPJ.',
                    [],
                    429,
                );

                Log::warning($lastFailure->message, [
                    ...$this->buildLogContext('rate_limited', $context, [
                        'metodo' => __METHOD__.'@'.__LINE__,
                        'provider' => $providerName,
                    ]),
                ]);

                continue;
            }

            $result = $resolvedProvider->provider->consult($cnpj);

            if ($result->isSuccess()) {
                return $this->handleProviderSuccess($cnpj, $providerName, $result, $context);
            }

            Log::warning('Falha ao consultar CNPJ no provedor', [
                ...$this->buildLogContext('provider_failure', $context, [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'provider' => $providerName,
                    'status' => $result->status,
                    'message' => $result->message,
                ]),
            ]);

            $lastFailure = $result;

            if (! $result->shouldRetryNextProvider) {
                break;
            }
        }

        $this->setError(
            $lastFailure?->message ?? 'Nao foi possivel consultar o CNPJ em nenhum provedor.',
            $lastFailure?->errors ?? [],
            $lastFailure?->status ?? 503,
        );
        $this->setData([
            'provider' => $lastProviderName,
            'cnpj' => $cnpj,
            'consultation_id' => $context['consultation_id'] ?? null,
            'status' => $lastFailure?->status ?? 503,
            'errors' => $lastFailure?->errors ?? [],
            'raw' => [
                'message' => $lastFailure?->message,
                'errors' => $lastFailure?->errors ?? [],
                'status' => $lastFailure?->status ?? 503,
            ],
        ]);

        Log::error($this->getMessage(), $this->buildLogContext('failure', $context, [
            'metodo' => __METHOD__.'@'.__LINE__,
            'provider' => $lastProviderName,
            'status' => $lastFailure?->status ?? 503,
            'errors' => $lastFailure?->errors ?? [],
        ]));

        return null;
    }

    /**
     * Retorna o VO do cache sem consultar API.
     */
    public function getFromCache(string $cnpj): ?CnpjVO
    {
        $cnpj = $this->document->sanitize($cnpj);
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
        $cnpj = $this->document->sanitize($cnpj);
        Cache::forget($this->buildCacheKey($cnpj));

        Log::info(__METHOD__.'@'.__LINE__, [
            'message' => 'Cache de consulta CNPJ invalidado',
            'cnpj' => $cnpj,
        ]);
    }

    private function checkRateLimit(array $providerConfig): bool
    {
        $providerName = (string) ($providerConfig['name'] ?? 'unknown');
        $maxAttempts = $this->getProviderRateLimitMaxAttempts($providerConfig);
        $decaySeconds = $this->getProviderRateLimitDecaySeconds($providerConfig);

        return RateLimiter::attempt(
            $this->buildRateLimiterKey($providerName),
            $maxAttempts,
            fn () => true,
            $decaySeconds,
        );
    }

    private function handleProviderSuccess(
        string $cnpj,
        string $providerName,
        CnpjProviderResult $result,
        array $context,
    ): ?CnpjVO {
        $data = $result->data ?? [];

        Cache::put($this->buildCacheKey($cnpj), $data, $this->getCacheTtl());

        try {
            $vo = CnpjVO::fromApiResponse($data);
        } catch (\Throwable $e) {
            $this->setError('Erro ao transformar dados do CNPJ.', [$e->getMessage()], 500);

            Log::error($this->getMessage(), $this->buildLogContext('vo_transformation_failure', $context, [
                'metodo' => __METHOD__.'@'.__LINE__,
                'provider' => $providerName,
                'exception' => $e->getMessage(),
            ]));

            return null;
        }

        $this->setSuccess("Consulta CNPJ realizada com sucesso ({$providerName})", [
            'provider' => $providerName,
            'cnpj' => $cnpj,
            'consultation_id' => $context['consultation_id'] ?? null,
            'company_name' => $vo->companyName,
            'raw' => $data,
        ]);

        $mainRegistration = $vo->getMainStateRegistration();

        Log::info($this->getMessage(), $this->buildLogContext('success', $context, [
            'metodo' => __METHOD__.'@'.__LINE__,
            'provider' => $providerName,
            'company_name' => $vo->companyName,
            'status_cnpj' => $vo->statusText,
            'city' => $vo->address->city,
            'state' => $vo->address->state,
            'registrations_count' => count($vo->registrations),
            'registrations' => array_map(fn ($r) => [
                'number' => $r->number,
                'state' => $r->state,
                'enabled' => $r->enabled,
                'status' => $r->statusText,
            ], $vo->registrations),
            'main_registration' => $mainRegistration ? [
                'number' => $mainRegistration->number,
                'state' => $mainRegistration->state,
            ] : null,
        ]));

        return $vo;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveContext(array $context, string $cnpj): array
    {
        $companyId = $context['company_id'] ?? $this->resolveCurrentCompanyId();
        $userId = $context['user_id'] ?? Auth::id();

        return [
            'consultation_id' => (string) ($context['consultation_id'] ?? Str::uuid()),
            'company_id' => is_numeric($companyId) ? (int) $companyId : null,
            'user_id' => is_numeric($userId) ? (int) $userId : null,
            'source' => (string) ($context['source'] ?? 'unknown'),
            'cnpj' => $cnpj,
        ];
    }

    private function resolveCurrentCompanyId(): ?int
    {
        $tenant = Filament::getTenant();

        if ($tenant?->id) {
            return (int) $tenant->id;
        }

        $user = Auth::user();

        if ($user && method_exists($user, 'getCurrentCompanyId')) {
            $companyId = $user->getCurrentCompanyId();

            return is_numeric($companyId) ? (int) $companyId : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function buildLogContext(string $event, array $context, array $extra = []): array
    {
        return [
            'log_identifier' => self::LOG_IDENTIFIER,
            'event' => $event,
            'consultation_id' => $context['consultation_id'] ?? null,
            'company_id' => $context['company_id'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'source' => $context['source'] ?? 'unknown',
            'cnpj' => $context['cnpj'] ?? null,
            ...$extra,
        ];
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

    private function getProviderRateLimitMaxAttempts(array $providerConfig): int
    {
        return max(1, (int) data_get(
            $providerConfig,
            'rate_limit.max_attempts',
            $this->getRateLimitMaxAttempts(),
        ));
    }

    private function getProviderRateLimitDecaySeconds(array $providerConfig): int
    {
        return max(1, (int) data_get(
            $providerConfig,
            'rate_limit.decay_seconds',
            $this->getRateLimitDecaySeconds(),
        ));
    }
}
