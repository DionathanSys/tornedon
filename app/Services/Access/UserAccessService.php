<?php

namespace App\Services\Access;

use App\Models\Company;
use App\Models\User;
use App\Models\UserCompanyAccess;

class UserAccessService
{
    public function settings(User $user, Company $company): ?UserCompanyAccess
    {
        return $user->companyAccesses()->where('company_id', $company->getKey())->first();
    }

    public function canAccessPanel(User $user, Company $company, string $panel): bool
    {
        return $user->canAccessTenant($company)
            && ($this->settings($user, $company)?->panels[$panel] ?? true);
    }

    public function allows(User $user, Company $company, string $permission, ?string $panel = null): bool
    {
        if (! $user->canAccessTenant($company)) {
            return false;
        }

        $settings = $this->settings($user, $company);

        return (! $panel || ($settings?->panels[$panel] ?? true))
            && (! $settings || in_array($permission, $settings->permissions, true));
    }
}
