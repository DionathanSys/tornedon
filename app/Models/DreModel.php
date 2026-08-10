<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DreModel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'template_key',
        'template_version',
        'structure_hash',
        'is_template_locked',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'template_version' => 'integer',
        'is_template_locked' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $model): void {
            if (! $model->is_default) {
                return;
            }

            static::query()
                ->where('company_id', $model->company_id)
                ->whereKeyNot($model->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DreLine::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function refreshStructureHash(): string
    {
        $hash = static::buildStructureHash($this);

        $this->forceFill(['structure_hash' => $hash])->saveQuietly();

        return $hash;
    }

    public function isStructurallyEquivalentTo(self $other): bool
    {
        $thisHash = $this->structure_hash ?: $this->refreshStructureHash();
        $otherHash = $other->structure_hash ?: $other->refreshStructureHash();

        return filled($this->template_key)
            && $this->template_key === $other->template_key
            && $thisHash === $otherHash;
    }

    public static function buildStructureHash(self $model): string
    {
        $lines = $model->lines()
            ->get()
            ->keyBy('id');

        $normalized = $lines
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(function (DreLine $line) use ($lines): array {
                $parentCode = $line->parent_id && $lines->has($line->parent_id)
                    ? (string) $lines->get($line->parent_id)->code
                    : null;

                return [
                    'code' => (string) $line->code,
                    'parent_code' => $parentCode,
                    'line_type' => $line->line_type?->value,
                    'operation' => $line->operation?->value,
                    'display_sign' => $line->display_sign?->value,
                    'sort_order' => (int) $line->sort_order,
                ];
            })
            ->values()
            ->all();

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }
}
