<?php

namespace App\Models;

use App\Enum\Financial\AccountingNature;
use App\Enum\Financial\ChartAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ChartAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'type',
        'nature',
        'is_postable',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'type' => ChartAccountType::class,
        'nature' => AccountingNature::class,
        'is_postable' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            $account->validateParent();
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

    public function categories(): HasMany
    {
        return $this->hasMany(FinancialCategory::class, 'chart_account_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_postable', true);
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

            if ($current->relationLoaded('parent')) {
                $current = $current->parent;

                continue;
            }

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

    public function root(): self
    {
        $current = $this;
        $visited = [];

        while ($current->parent_id) {
            if (isset($visited[(int) $current->id])) {
                break;
            }

            $visited[(int) $current->id] = true;
            $current = $current->parent()->first() ?? $current;
        }

        return $current;
    }

    /**
     * @return Collection<int, self>
     */
    public function ancestors(): Collection
    {
        $ancestors = collect();
        $visited = [];
        $current = $this->parent()->first();

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            $ancestors->push($current);
            $current = $current->parent()->first();
        }

        return $ancestors;
    }

    /**
     * @return Collection<int, self>
     */
    public function descendants(bool $includeSelf = false): Collection
    {
        $descendants = $includeSelf ? collect([$this]) : collect();
        $queue = collect([$this]);
        $visited = [];

        while ($queue->isNotEmpty()) {
            /** @var self $current */
            $current = $queue->shift();

            if (isset($visited[(int) $current->id])) {
                continue;
            }

            $visited[(int) $current->id] = true;

            $children = static::query()
                ->where('company_id', $this->company_id)
                ->where('parent_id', $current->id)
                ->orderBy('sort_order')
                ->orderBy('code')
                ->orderBy('name')
                ->get();

            foreach ($children as $child) {
                $descendants->push($child);
                $queue->push($child);
            }
        }

        return $descendants;
    }

    public static function optionsForCompany(int $companyId, bool $postableOnly = false): array
    {
        $accounts = static::query()
            ->where('company_id', $companyId)
            ->active()
            ->when($postableOnly, fn (Builder $query): Builder => $query->postable())
            ->orderBy('sort_order')
            ->orderBy('code')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'code', 'name']);

        return $accounts
            ->mapWithKeys(fn (self $account): array => [$account->id => static::buildFullNameFromMap($account, $accounts->keyBy('id'))])
            ->toArray();
    }

    /**
     * @param  Collection<int|string, self>  $accountsById
     */
    protected static function buildFullNameFromMap(self $account, Collection $accountsById): string
    {
        $segments = [];
        $visited = [];
        $current = $account;

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;
            array_unshift($segments, $current->display_name);

            $parentId = (int) ($current->parent_id ?? 0);

            if ($parentId === 0 || ! $accountsById->has($parentId)) {
                break;
            }

            $current = $accountsById->get($parentId);
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
                'parent_id' => ['Uma conta nao pode ser pai dela mesma.'],
            ]);
        }

        $parent = static::query()->find($this->parent_id);

        if (! $parent || (int) $parent->company_id !== (int) $this->company_id) {
            throw ValidationException::withMessages([
                'parent_id' => ['A conta pai deve pertencer a mesma empresa.'],
            ]);
        }

        $current = $parent;
        $visited = [];

        while ($current) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A hierarquia do plano de contas contem um ciclo.'],
                ]);
            }

            if ($this->exists && $currentId === (int) $this->id) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A conta pai nao pode ser descendente da conta atual.'],
                ]);
            }

            $visited[$currentId] = true;
            $current = $current->parent()->first();
        }
    }
}
