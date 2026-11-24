<?php

namespace App\Models\Concerns;

use App\Models\BankReconciliationGroupItem;
use App\Support\Financial\GroupedFinancialEffect;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;

trait HasGroupedFinancialEffect
{
    protected static function bootHasGroupedFinancialEffect(): void
    {
        static::updating(fn ($model) => GroupedFinancialEffect::assertMutable($model));
        static::deleting(fn ($model) => GroupedFinancialEffect::assertMutable($model));
    }

    public function scopeEffective(Builder $query): Builder
    {
        return GroupedFinancialEffect::effective($query, $this->qualifyColumn('id'), GroupedFinancialEffect::reference($this));
    }

    public function reconciliationComponent(): HasOne
    {
        return $this->hasOne(BankReconciliationGroupItem::class, GroupedFinancialEffect::reference($this));
    }
}
