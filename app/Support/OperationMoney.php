<?php

namespace App\Support;

final class OperationMoney
{
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', str_replace('.', '', $value));
        }

        return $value;
    }

    public static function amount(mixed $value): float
    {
        $value = self::normalize($value);

        return is_numeric($value) ? (float) $value : 0;
    }
}
