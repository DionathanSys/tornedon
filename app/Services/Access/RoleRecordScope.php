<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/** Request actor only. Queues/webhooks without an actor retain their explicit business scoping. */
class RoleRecordScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (! $user instanceof User || ! app(CompanyAuthorization::class)->isScopedUser($user)) {
            return;
        }
        if (! $user->isActive()) {
            $builder->whereRaw('1 = 0');

            return;
        }
        $child = PermissionCatalog::children()[$model::class] ?? null;
        if ($child) {
            $builder->whereHas($child[0]); // The parent's own global scope applies the same authorization.

            return;
        }
        $definition = PermissionCatalog::definition($model::class);
        if (! $definition) {
            return;
        }
        $access = app(CompanyAuthorization::class);
        $companies = $user->companies()->where('companies.is_active', true)->wherePivot('is_active', true)->get();
        $builder->where(function (Builder $allowed) use ($companies, $user, $access, $definition, $model): void {
            $allowed->whereRaw('1 = 0');
            foreach ($companies as $company) {
                $allowed->orWhere(function (Builder $tenantQuery) use ($company, $user, $access, $definition, $model): void {
                    if ($access->isManaged($user, $company)) {
                        $access->scopeQuery($tenantQuery, $user, $company, $definition['module'].'.view');
                    } else {
                        $tenantQuery->where($model->qualifyColumn('company_id'), $company->id);
                    }
                });
            }
        });
    }
}
