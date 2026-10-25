<?php

namespace App\Services\Access;

use App\Models\Company;
use App\Models\CompanyRoleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompanyAuthorization
{
    public function isScopedUser(User $user): bool
    {
        return (bool) DB::table('users')->where('id', $user->id)->value('scoped_access_enabled');
    }

    public function membership(User $user, Company $company): ?object
    {
        return DB::table('company_user')->where('user_id', $user->id)->where('company_id', $company->id)->where('is_active', true)->first();
    }

    public function isManaged(User $user, Company $company): bool
    {
        return $this->membership($user, $company)?->access_mode === 'roles';
    }

    /** Re-read grants on every decision: revocation must also affect already-mounted Livewire components. */
    public function assignments(User $user, Company $company): Collection
    {
        if (! $user->isActive() || ! $company->is_active || ! $this->membership($user, $company)) {
            return collect();
        }

        return CompanyRoleAssignment::query()->with(['role', 'engagement'])
            ->where('user_id', $user->id)->where('company_id', $company->id)->where('is_active', true)->get()
            ->filter(function (CompanyRoleAssignment $assignment) use ($company): bool {
                if (! $assignment->role?->is_active || (int) $assignment->role->company_id !== (int) $company->id) {
                    return false;
                }
                if (! $assignment->bpo_engagement_id) {
                    return true;
                }
                $engagement = $assignment->engagement;
                $responsible = $engagement?->responsible;

                return $engagement?->is_active
                    && (int) $engagement->company_id === (int) $company->id
                    && $responsible?->isActive()
                    && $this->membership($responsible, $company) !== null;
            });
    }

    public function scopes(User $user, Company $company, string $permission): array
    {
        return $this->assignments($user, $company)->map(function ($assignment) use ($company, $permission) {
            if ($assignment->bpo_engagement_id && (int) $assignment->engagement->responsible_id !== (int) $assignment->user_id) {
                if (($assignment->engagement->delegable_permissions[$permission] ?? null) !== 'company'
                    || ! $this->assignments($assignment->engagement->responsible, $company)->contains(fn ($source) => (! $source->bpo_engagement_id || (int) $source->engagement->responsible_id === (int) $source->user_id)
                        && ($source->role->permissions[$permission] ?? null) === 'company')) {
                    return 'none';
                }
            }

            return $assignment->role->permissions[$permission] ?? 'none';
        })
            ->filter(fn ($scope) => in_array($scope, ['own', 'assigned', 'company'], true))->unique()->values()->all();
    }

    public function allows(User $user, Company $company, string $permission): bool
    {
        return $this->scopes($user, $company, $permission) !== [];
    }

    public function canManageTeam(User $user, Company $company): bool
    {
        if (! $user->isActive() || ! $company->is_active) {
            return false;
        }
        if ($user->canManageUsers()) {
            return true; // Platform administration, not an operational tenant bypass.
        }
        $membership = $this->membership($user, $company);

        return $membership && (($membership->access_mode === 'legacy' && $membership->role === 'owner')
            || $this->allows($user, $company, 'team.manage'));
    }

    public function canAccessPanel(User $user, Company $company, string $panel): bool
    {
        return $this->assignments($user, $company)->contains(fn ($assignment) => in_array($panel, $assignment->role->panels, true));
    }

    public function allowsRecord(User $user, Company $company, string $permission, Model $record): bool
    {
        if ((int) $record->getAttribute('company_id') !== (int) $company->id) {
            return false;
        }
        $definition = PermissionCatalog::definition($record::class);
        if (! $definition) {
            return false;
        }
        $scopes = $this->scopes($user, $company, $permission);
        if (in_array('company', $scopes, true)) {
            return true;
        }
        if ($definition['owner'] && in_array('own', $scopes, true) && (int) $record->getRawOriginal($definition['owner']) === (int) $user->id) {
            return true;
        }

        return in_array('assigned', $scopes, true) && collect($definition['assigned'])
            ->contains(fn ($column) => (int) $record->getRawOriginal($column) === (int) $user->id);
    }

    public function scopeQuery(Builder $query, User $user, Company $company, string $permission): Builder
    {
        $model = $query->getModel();
        $query->where($model->qualifyColumn('company_id'), $company->id);
        $definition = PermissionCatalog::definition($model::class);
        $scopes = $this->scopes($user, $company, $permission);
        if (! $definition || $scopes === []) {
            return $query->whereRaw('1 = 0');
        }
        if (in_array('company', $scopes, true)) {
            return $query;
        }

        return $query->where(function (Builder $restricted) use ($model, $definition, $scopes, $user): void {
            $restricted->whereRaw('1 = 0');
            if ($definition['owner'] && in_array('own', $scopes, true)) {
                $restricted->orWhere($model->qualifyColumn($definition['owner']), $user->id);
            }
            if (in_array('assigned', $scopes, true)) {
                foreach ($definition['assigned'] as $column) {
                    $restricted->orWhere($model->qualifyColumn($column), $user->id);
                }
            }
        });
    }

    public function explain(User $user, Company $company): array
    {
        $result = [];
        foreach ($this->assignments($user, $company) as $assignment) {
            foreach ($assignment->role->permissions as $permission => $scope) {
                if ($scope !== 'none') {
                    $result[$permission][] = ['scope' => $scope, 'role' => $assignment->role->name, 'origin' => $assignment->bpo_engagement_id ? 'bpo' : 'direct'];
                }
            }
        }

        return $result;
    }
}
