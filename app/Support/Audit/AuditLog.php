<?php

namespace App\Support\Audit;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use UnitEnum;

class AuditLog
{
    public static function payload(array $data, array $fields): array
    {
        return self::normalizeArray(Arr::only($data, $fields));
    }

    public static function snapshot(Model $model, array $fields): array
    {
        return self::normalizeArray(Arr::only($model->attributesToArray(), $fields));
    }

    public static function diff(array $before, array $after): array
    {
        $diff = [];
        $keys = array_unique([
            ...array_keys($before),
            ...array_keys($after),
        ]);

        sort($keys);

        foreach ($keys as $key) {
            $beforeValue = $before[$key] ?? null;
            $afterValue = $after[$key] ?? null;

            if (self::valuesAreEqual($beforeValue, $afterValue)) {
                continue;
            }

            $diff[$key] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $diff;
    }

    private static function normalizeArray(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[$key] = self::normalizeValue($value);
        }

        ksort($normalized);

        return $normalized;
    }

    private static function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalizeValue(...), $value);
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalizeValue($item);
        }

        ksort($normalized);

        return $normalized;
    }

    private static function valuesAreEqual(mixed $before, mixed $after): bool
    {
        return json_encode(self::normalizeValue($before)) === json_encode(self::normalizeValue($after));
    }
}
