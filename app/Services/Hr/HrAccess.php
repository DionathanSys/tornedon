<?php

namespace App\Services\Hr;

use App\Models\Company;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use App\Services\Access\UserAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class HrAccess
{
    public function allows(User $user, Company $company, string $permission, ?Model $record = null): bool
    {
        $access = app(CompanyAuthorization::class);
        $membership = $access->membership($user, $company);
        if (! $user->isActive() || ! $company->is_active || ! $membership || ! $user->canAccessTenant($company)
            || ($record && (int) $record->company_id !== (int) $company->id)) {
            return false;
        }
        if ($access->isManaged($user, $company)) {
            return $record ? $access->allowsRecord($user, $company, $permission, $record) : $access->allows($user, $company, $permission);
        }

        // Legacy owners retain management access; legacy unrestricted memberships do not imply RH access.
        return $membership->role === 'owner'
            || in_array($permission, app(UserAccessService::class)->settings($user, $company)?->permissions ?? [], true);
    }

    public function authorize(User $user, Company $company, string $permission, ?Model $record = null): void
    {
        throw_unless($this->allows($user, $company, $permission, $record), AuthorizationException::class);
    }
}
