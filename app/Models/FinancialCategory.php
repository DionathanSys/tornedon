<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FinancialCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'chart_account_id',
        'parent_id',
        'name',
        'description',
        'sort_order',
        'is_active',
        'allow_payable',
        'allow_receivable',
        'allow_cash_movement',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_payable' => 'boolean',
        'allow_receivable' => 'boolean',
        'allow_cash_movement' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->validateChartAccount();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chartAccount(): BelongsTo
    {
        return $this->belongsTo(ChartAccount::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLeaves(Builder $query): Builder
    {
        return $query->whereDoesntHave('children');
    }

    public function scopeAllowedFor(Builder $query, string $scope): Builder
    {
        return match ($scope) {
            'payable' => $query->where('allow_payable', true),
            'receivable' => $query->where('allow_receivable', true),
            'cash_movement' => $query->where('allow_cash_movement', true),
            default => $query,
        };
    }

    public function isLeaf(): bool
    {
        return ! $this->children()->exists();
    }

    public function allows(string $scope): bool
    {
        return match ($scope) {
            'payable' => $this->allow_payable,
            'receivable' => $this->allow_receivable,
            'cash_movement' => $this->allow_cash_movement,
            default => false,
        };
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
            array_unshift($segments, $current->name);

            if ($current->relationLoaded('parent')) {
                $current = $current->parent;

                continue;
            }

            $parentId = (int) ($current->parent_id ?? 0);

            if ($parentId === 0) {
                break;
            }

            $current = static::query()
                ->select(['id', 'parent_id', 'name'])
                ->find($parentId);
        }

        return implode(' / ', $segments);
    }

    public static function optionsForCompany(int $companyId, string $scope, bool $leavesOnly = true): array
    {
        $categories = static::query()
            ->where('company_id', $companyId)
            ->active()
            ->allowedFor($scope)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);

        return static::formatOptions($categories, $leavesOnly);
    }

    public static function hierarchyOptionsForCompany(int $companyId): array
    {
        $categories = static::query()
            ->where('company_id', $companyId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name']);

        return static::formatOptions($categories, false);
    }

    /**
     * @param  Collection<int, self>  $categories
     * @return array<int, string>
     */
    public static function formatOptions(Collection $categories, bool $leavesOnly = true): array
    {
        $categoriesById = $categories->keyBy('id');
        $parentIds = $categories
            ->pluck('parent_id')
            ->filter()
            ->map(static fn (mixed $parentId): int => (int) $parentId);

        return $categories
            ->filter(function (self $category) use ($leavesOnly, $parentIds): bool {
                if (! $leavesOnly) {
                    return true;
                }

                return ! $parentIds->contains((int) $category->id);
            })
            ->mapWithKeys(fn (self $category) => [$category->id => static::buildFullNameFromMap($category, $categoriesById)])
            ->toArray();
    }

    /**
     * @param  Collection<int|string, self>  $categoriesById
     */
    protected static function buildFullNameFromMap(self $category, Collection $categoriesById): string
    {
        $segments = [];
        $visited = [];
        $current = $category;

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            array_unshift($segments, $current->name);

            $parentId = (int) ($current->parent_id ?? 0);

            if ($parentId === 0) {
                break;
            }

            if ($categoriesById->has($parentId)) {
                $current = $categoriesById->get($parentId);

                continue;
            }

            $current = static::query()
                ->select(['id', 'parent_id', 'name'])
                ->find($parentId);
        }

        return implode(' / ', $segments);
    }

    private function validateChartAccount(): void
    {
        if ($this->chart_account_id === null) {
            return;
        }

        $account = ChartAccount::query()->find($this->chart_account_id);

        if (! $account || (int) $account->company_id !== (int) $this->company_id) {
            throw ValidationException::withMessages([
                'chart_account_id' => ['A conta do plano deve pertencer a mesma empresa da categoria.'],
            ]);
        }
    }
}
