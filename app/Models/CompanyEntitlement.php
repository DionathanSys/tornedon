<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyEntitlement extends Model
{
    protected $fillable = [
        'company_id',
        'feature',
        'enabled',
        'starts_at',
        'ends_at',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('enabled', true)
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public static function enabledFor(int $companyId, string $feature): bool
    {
        return static::query()
            ->where('company_id', $companyId)
            ->where('feature', $feature)
            ->active()
            ->exists();
    }
}
