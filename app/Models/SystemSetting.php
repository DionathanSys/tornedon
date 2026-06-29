<?php

namespace App\Models;

use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(self::cacheKey($key), now()->addHours(24), function () use ($key, $default) {
            try {
                $setting = self::query()
                    ->where('key', $key)
                    ->first();
            } catch (QueryException $e) {
                if (self::isMissingTableException($e)) {
                    return $default;
                }

                throw $e;
            }

            return $setting?->value ?? $default;
        });
    }

    public static function set(string $key, mixed $value): self
    {
        Cache::forget(self::cacheKey($key));

        try {
            return self::updateOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        } catch (QueryException $e) {
            if (self::isMissingTableException($e)) {
                throw new RuntimeException('A tabela system_settings ainda não existe. Execute php artisan migrate.', previous: $e);
            }

            throw $e;
        }
    }

    public static function remove(string $key): bool
    {
        Cache::forget(self::cacheKey($key));

        try {
            return self::query()
                ->where('key', $key)
                ->delete() > 0;
        } catch (QueryException $e) {
            if (self::isMissingTableException($e)) {
                return false;
            }

            throw $e;
        }
    }

    private static function cacheKey(string $key): string
    {
        return "system_setting_{$key}";
    }

    private static function isMissingTableException(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Base table or view not found')
            || str_contains($message, 'no such table');
    }
}
