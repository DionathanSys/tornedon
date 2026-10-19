<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

abstract class CommitmentRecord extends Model
{
    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if ($record->exists && $record->isDirty(['company_id', 'customer_id'])) {
                throw ValidationException::withMessages(['company_id' => 'Não é permitido transferir registros de empenhos entre empresas ou clientes.']);
            }

            if (! Company::query()->whereKey($record->company_id)->whereHas('partners', fn (Builder $query) => $query->whereKey($record->customer_id))->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'O cliente não pertence à empresa do empenho.']);
            }

            foreach ($record->contextRelations() as $relationName) {
                $relation = $record->{$relationName}();
                $foreignKey = $relation->getForeignKeyName();

                if ($record->getAttribute($foreignKey) === null) {
                    continue;
                }

                $related = $relation->getQuery()->first();
                if (! $related || (int) $related->company_id !== (int) $record->company_id
                    || ($related->getAttribute('customer_id') !== null && (int) $related->customer_id !== (int) $record->customer_id)) {
                    throw ValidationException::withMessages([$foreignKey => 'O vínculo deve pertencer à mesma empresa e ao mesmo cliente.']);
                }
            }

            $record->validateContext();
        });
    }

    /** @return list<string> */
    protected function contextRelations(): array
    {
        return [];
    }

    protected function validateContext(): void {}

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where($this->qualifyColumn('company_id'), $companyId);
    }

    public function scopeForCustomer(Builder $query, int $companyId, int $customerId): Builder
    {
        return $query->forCompany($companyId)->where($this->qualifyColumn('customer_id'), $customerId);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }
}
