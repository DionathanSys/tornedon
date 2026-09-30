<?php

namespace App\Services\Financial\Banking\Providers;

use Throwable;

final class IntegraBancosExceptionFormatter
{
    /**
     * @return array<string, mixed>
     */
    public static function details(Throwable $exception): array
    {
        $message = $exception->getMessage();
        $decoded = json_decode($message, true);

        if (! is_array($decoded)) {
            return [
                'message' => self::truncate($message),
            ];
        }

        return self::sanitize($decoded);
    }

    public static function message(Throwable $exception): string
    {
        $details = self::details($exception);
        $parts = [];

        foreach (['message', 'error', 'response', 'json_error'] as $key) {
            if (! array_key_exists($key, $details) || blank($details[$key])) {
                continue;
            }

            $value = is_array($details[$key])
                ? json_encode($details[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $details[$key];

            if (filled($value)) {
                $parts[] = $key === 'message' ? $value : $key.': '.$value;
            }
        }

        if (array_key_exists('http_code', $details) && filled($details['http_code'])) {
            $parts[] = 'http_code: '.(string) $details['http_code'];
        }

        return self::truncate(implode(' | ', $parts) ?: $exception->getMessage());
    }

    public static function sanitizeData(mixed $value): mixed
    {
        return self::sanitize($value);
    }

    private static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = self::sanitize($childValue, (string) $childKey);
            }

            return $sanitized;
        }

        return is_string($value) ? self::truncate($value) : $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return in_array($normalized, [
            'authorization',
            'client_secret',
            'credentials',
            'headers',
            'password',
            'refresh_token',
            'secret_key',
            'x_api_key',
            'access_token',
            'config',
        ], true)
            || str_contains($normalized, 'token');
    }

    private static function truncate(string $value): string
    {
        return mb_strlen($value) > 2000
            ? mb_substr($value, 0, 2000).'...'
            : $value;
    }
}
