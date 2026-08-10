<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class CostCenter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $center): void {
            $center->validateParent();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('name');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function getDisplayNameAttribute(): string
    {
        return trim(($this->code ? $this->code.' - ' : '').$this->name);
    }

    public function getFullNameAttribute(): string
    {
        $segments = [];
        $visited = [];
        $current = $this;

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            array_unshift($segments, $current->display_name);

            $parentId = (int) ($current->parent_id ?? 0);

            if ($parentId === 0) {
                break;
            }

            $current = static::query()
                ->select(['id', 'parent_id', 'code', 'name'])
                ->find($parentId);
        }

        return implode(' / ', $segments);
    }

    /**
     * @return Collection<int, self>
     */
    public static function optionsForCompany(int $companyId): array
    {
        $centers = static::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'code', 'name']);

        return $centers
            ->mapWithKeys(fn (self $center): array => [$center->id => static::buildFullNameFromMap($center, $centers->keyBy('id'))])
            ->toArray();
    }

    protected static function buildFullNameFromMap(self $center, Collection $centersById): string
    {
        $segments = [];
        $visited = [];
        $current = $center;

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            array_unshift($segments, $current->display_name);

            $parentId = (int) ($current->parent_id ?? 0);
            if ($parentId === 0 || ! $centersById->has($parentId)) {
                break;
            }

            $current = $centersById->get($parentId);
        }

        return implode(' / ', $segments);
    }

    private function validateParent(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        if ($this->exists && (int) $this->parent_id === (int) $this->id) {
            throw ValidationException::withMessages([
                'parent_id' => ['Um centro de custo não pode ser pai dele mesmo.'],
            ]);
        }

        $parent = static::query()->find($this->parent_id);

        if (! $parent || (int) $parent->company_id !== (int) $this->company_id) {
            throw ValidationException::withMessages([
                'parent_id' => ['O centro de custo pai deve pertencer a mesma empresa.'],
            ]);
        }

        $current = $parent;
        $visited = [];

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A hierarquia de centros de custo contem um ciclo.'],
                ]);
            }

            if ($this->exists && $currentId === (int) $this->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['O centro de custo pai não pode ser descendente do centro atual.'],
                ]);
            }

            $visited[$currentId] = true;
            $current = $current->parent()->first();
        }
    }
}
