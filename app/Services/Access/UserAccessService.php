<?php

namespace App\Services\Access;

use App\Models\Company;
use App\Models\User;
use App\Models\UserCompanyAccess;
use Filament\Facades\Filament;

class UserAccessService
{
    public function settings(User $user, Company $company): ?UserCompanyAccess
    {
        return $user->companyAccesses()->where('company_id', $company->getKey())->first();
    }

    public function canAccessPanel(User $user, Company $company, string $panel): bool
    {
        $authorization = app(CompanyAuthorization::class);
        if ($panel === 'bpo') {
            return $authorization->canAccessPanel($user, $company, $panel);
        }
        if ($authorization->isManaged($user, $company)) {
            return $authorization->canAccessPanel($user, $company, $panel);
        }

        return $user->canAccessTenant($company)
            && ($this->settings($user, $company)?->panels[$panel] ?? true);
    }

    public function allows(User $user, Company $company, string $permission, ?string $panel = null): bool
    {
        if ($panel === null && Filament::getCurrentPanel()?->getId() === 'bpo') {
            $panel = 'bpo';
        }
        if (! $user->canAccessTenant($company)) {
            return false;
        }

        $authorization = app(CompanyAuthorization::class);
        if ($authorization->requiresRoleAuthorization($user, $company, $panel)) {
            $permission = str_ends_with($permission, '.access') ? substr($permission, 0, -7).'.view' : $permission;
            if ($permission === 'commercial_dashboard.view') {
                $permission = 'reports.view';
            }

            return (! $panel || $authorization->canAccessPanel($user, $company, $panel))
                && $authorization->allows($user, $company, $permission);
        }

        $settings = $this->settings($user, $company);

        return (! $panel || ($settings?->panels[$panel] ?? true))
            && (! $settings || in_array($permission, $settings->permissions, true));
    }
}
