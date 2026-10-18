<?php

namespace App\Services\Financial\Banking\Providers;

use Carbon\CarbonImmutable;

/** Keeps API response codes separate from charge and requested-event states. */
final class IntegraBancosResponse
{
    public static function code(array $payload): ?string
    {
        return self::text($payload['codigo'] ?? null);
    }

    public static function status(array $payload): ?string
    {
        return self::text(data_get($payload, 'dados.status.codigo') ?? data_get($payload, 'data.status.codigo')
            ?? data_get($payload, 'status.codigo') ?? data_get($payload, 'status.code'));
    }

    public static function accepted(array $payload): bool
    {
        return in_array($payload['sucesso'] ?? $payload['success'] ?? null, [true, 'true', 1, '1'], true);
    }

    public static function pending(array $payload): bool
    {
        return self::code($payload) === '4' && self::status($payload) === null;
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        if (str_ends_with($value, 'Z')) {
            $value = substr($value, 0, -1).'+00:00';
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value, config('app.timezone'));
                if ($date && $date->format($format) === $value) {
                    return $date;
                }
            } catch (\Throwable) {
                // Try the next explicitly supported format; never replace invalid dates with now().
            }
        }

        return null;
    }

    public static function paymentDate(array $payload): mixed
    {
        return data_get($payload, 'dados.data_pagamento') ?? data_get($payload, 'data.data_pagamento') ?? $payload['data_pagamento'] ?? null;
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
