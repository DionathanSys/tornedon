<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CardInstitution extends Model
{
    // Keep the existing table and foreign keys to preserve historical receivables.
    protected $table = 'card_payment_profiles';

    protected $attributes = [
        'active' => true,
        'is_default' => false,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'brand',
        'acquirer',
        'settlement_days',
        'active',
        'is_default',
    ];

    protected $casts = [
        'settlement_days' => 'integer',
        'active' => 'boolean',
        'is_default' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function accountReceivables(): HasMany
    {
        return $this->hasMany(AccountReceivable::class, 'card_payment_profile_id');
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            Company::query()->whereKey($this->company_id)->lockForUpdate()->firstOrFail();

            if (! $this->active) {
                $this->is_default = false;
            }

            if ($this->is_default) {
                static::query()->where('company_id', $this->company_id)
                    ->when($this->exists, fn (Builder $query) => $query->whereKeyNot($this->id))
                    ->where('is_default', true)->update(['is_default' => false]);
            }

            return parent::save($options);
        });
    }

    public static function defaultIdForCompany(int $companyId): ?int
    {
        return static::query()->where('company_id', $companyId)->active()
            ->where('is_default', true)->value('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public static function optionsForCompany(int $companyId): array
    {
        return static::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }
}
