<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
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
        $parentName = $this->relationLoaded('parent')
            ? $this->parent?->full_name
            : $this->parent()->with('parent')->first()?->full_name;

        return $parentName ? "{$parentName} / {$this->name}" : $this->name;
    }

    public static function optionsForCompany(int $companyId, string $scope): array
    {
        return static::query()
            ->where('company_id', $companyId)
            ->active()
            ->allowedFor($scope)
            ->with('parent.parent')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (self $category) => $category->isLeaf())
            ->mapWithKeys(fn (self $category) => [$category->id => $category->full_name])
            ->toArray();
    }
}
