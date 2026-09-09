<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->canManageUsers() || $user->belongsToCompany($company->id);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->canManageUsers() || $user->belongsToCompany($company->id);
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }
}
