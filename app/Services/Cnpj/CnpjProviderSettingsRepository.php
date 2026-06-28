<?php

namespace App\Services\Cnpj;

use App\Models\SystemSetting;

class CnpjProviderSettingsRepository
{
    public const SETTINGS_KEY = 'cnpj.providers';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $saved = SystemSetting::get(self::SETTINGS_KEY);

        if (! is_array($saved) || ! is_array($saved['providers'] ?? null)) {
            return $this->defaultProviders();
        }

        return $this->normalizeProviders($saved['providers']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $provider): bool => (bool) ($provider['enabled'] ?? false),
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $providers
     */
    public function save(array $providers): void
    {
        SystemSetting::set(self::SETTINGS_KEY, [
            'providers' => $this->normalizeProviders($providers),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return (array) config('cnpj.provider_labels', []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function defaultProviders(): array
    {
        $providerClasses = (array) config('cnpj.provider_classes', []);
        $providerSettings = (array) config('cnpj.provider_settings', []);
        $defaultEnabledNames = $this->configuredProviderNames();
        $labels = $this->labels();
        $providers = [];

        foreach (array_keys($providerClasses) as $name) {
            $providers[] = $this->normalizeProvider([
                'name' => $name,
                'enabled' => in_array($name, $defaultEnabledNames, true),
                'label' => $labels[$name] ?? $name,
                ...((array) ($providerSettings[$name] ?? [])),
            ]);
        }

        usort($providers, function (array $left, array $right) use ($defaultEnabledNames): int {
            $leftIndex = array_search($left['name'], $defaultEnabledNames, true);
            $rightIndex = array_search($right['name'], $defaultEnabledNames, true);

            $leftIndex = $leftIndex === false ? PHP_INT_MAX : $leftIndex;
            $rightIndex = $rightIndex === false ? PHP_INT_MAX : $rightIndex;

            return $leftIndex <=> $rightIndex;
        });

        return $providers;
    }

    /**
     * @param  array<int, array<string, mixed>>  $providers
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProviders(array $providers): array
    {
        $defaultsByName = [];

        foreach ($this->defaultProviders() as $provider) {
            $defaultsByName[$provider['name']] = $provider;
        }

        $normalized = [];

        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }

            $name = (string) ($provider['name'] ?? '');

            if ($name === '' || ! array_key_exists($name, $defaultsByName)) {
                continue;
            }

            $normalized[] = $this->normalizeProvider([
                ...$defaultsByName[$name],
                ...$provider,
            ]);

            unset($defaultsByName[$name]);
        }

        foreach ($defaultsByName as $provider) {
            $normalized[] = $provider;
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>
     */
    private function normalizeProvider(array $provider): array
    {
        $labels = $this->labels();
        $name = (string) ($provider['name'] ?? '');
        $headers = is_array($provider['headers'] ?? null) ? $provider['headers'] : [];

        return [
            'name' => $name,
            'label' => $labels[$name] ?? (string) ($provider['label'] ?? $name),
            'enabled' => (bool) ($provider['enabled'] ?? false),
            'base_url' => trim((string) ($provider['base_url'] ?? '')),
            'timeout' => max(1, (int) ($provider['timeout'] ?? 15)),
            'headers' => collect($headers)
                ->mapWithKeys(fn (mixed $value, mixed $key): array => [trim((string) $key) => trim((string) $value)])
                ->filter(fn (string $value, string $key): bool => $key !== '' && $value !== '')
                ->all(),
            'rate_limit' => [
                'max_attempts' => max(1, (int) data_get($provider, 'rate_limit.max_attempts', 5)),
                'decay_seconds' => max(1, (int) data_get($provider, 'rate_limit.decay_seconds', 60)),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function configuredProviderNames(): array
    {
        $providerNames = config('cnpj.providers', ['brasil_api']);

        if (is_string($providerNames)) {
            $providerNames = array_values(array_filter(array_map(
                static fn (string $name): string => trim($name),
                explode(',', $providerNames),
            )));
        }

        return is_array($providerNames) ? array_values($providerNames) : [];
    }
}
