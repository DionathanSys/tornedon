<?php

namespace App\Models\Concerns;

use App\Models\AccountPayableInstallment;
use Illuminate\Database\Eloquent\Builder;

trait HasFinancialConsideration
{
    public function scopeConsidered(Builder $query): Builder
    {
        return $query->whereNull($this->qualifyColumn('ignored_at'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        $relation = $this instanceof AccountPayableInstallment ? 'accountPayable' : 'accountReceivable';

        return $query->considered()->where($this->qualifyColumn('status'), '!=', 'cancelled')
            ->where($this->qualifyColumn('balance_amount'), '>', 0)
            ->whereHas($relation, fn (Builder $q) => $q->where('status', '!=', 'cancelled'));
    }
}
