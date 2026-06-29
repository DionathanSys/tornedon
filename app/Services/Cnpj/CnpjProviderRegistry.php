<?php

namespace App\Services\Cnpj;

use App\Services\Cnpj\Contracts\CnpjApiProviderInterface;
use App\Services\Cnpj\DTO\ResolvedCnpjProvider;
use Illuminate\Support\Facades\Log;

class CnpjProviderRegistry
{
    public function __construct(
        private readonly CnpjProviderSettingsRepository $settingsRepository,
    ) {}

    /**
     * @return array<int, ResolvedCnpjProvider>
     */
    public function resolveEnabled(): array
    {
        $providerClasses = (array) config('cnpj.provider_classes', []);
        $providers = [];

        foreach ($this->settingsRepository->enabled() as $providerConfig) {
            $providerName = (string) $providerConfig['name'];
            $providerClass = $providerClasses[$providerName] ?? null;

            if (! is_string($providerClass) || ! class_exists($providerClass)) {
                Log::error('Provider de CNPJ nao encontrado.', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'provider' => $providerName,
                    'class' => $providerClass,
                ]);

                continue;
            }

            try {
                $provider = app()->makeWith($providerClass, [
                    'config' => $providerConfig,
                ]);
            } catch (\Throwable $e) {
                Log::error('Falha ao instanciar provider de CNPJ.', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'provider' => $providerName,
                    'class' => $providerClass,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $provider instanceof CnpjApiProviderInterface) {
                Log::error('Classe do provider nao implementa o contrato esperado.', [
                    'metodo' => __METHOD__.'@'.__LINE__,
                    'provider' => $providerName,
                    'class' => $providerClass,
                ]);

                continue;
            }

            $providers[] = new ResolvedCnpjProvider($providerName, $provider, $providerConfig);
        }

        return $providers;
    }
}
