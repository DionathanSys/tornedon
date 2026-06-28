<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

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
            $setting = self::query()
                ->where('key', $key)
                ->first();

            return $setting?->value ?? $default;
        });
    }

    public static function set(string $key, mixed $value): self
    {
        Cache::forget(self::cacheKey($key));

        return self::updateOrCreate(
            ['key' => $key],
            ['value' => $value],
        );
    }

    public static function remove(string $key): bool
    {
        Cache::forget(self::cacheKey($key));

        return self::query()
            ->where('key', $key)
            ->delete() > 0;
    }

    private static function cacheKey(string $key): string
    {
        return "system_setting_{$key}";
    }
}
