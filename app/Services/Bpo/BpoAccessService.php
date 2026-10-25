<?php

namespace App\Services\Bpo;

use App\Models\Company;
use App\Models\CompanyRoleAssignment;
use App\Models\User;
use App\Services\Access\CompanyAuthorization;
use Illuminate\Support\Collection;

class BpoAccessService
{
    public function __construct(private CompanyAuthorization $authorization) {}

    public function companies(User $user): Collection
    {
        if (! $user->isActive()) {
            return collect();
        }
        $ids = CompanyRoleAssignment::where('user_id', $user->id)->where('is_active', true)->whereNotNull('bpo_engagement_id')->pluck('company_id')->unique();

        return $user->companies()->whereIn('companies.id', $ids)->where('companies.is_active', true)->wherePivot('is_active', true)
            ->orderBy('companies.name')->get()->filter(fn (Company $company) => $this->canAccessCompany($user, $company))->values();
    }

    public function canAccessCompany(User $user, Company $company): bool
    {
        return $this->authorization->assignments($user, $company)->contains(fn ($assignment) => $assignment->bpo_engagement_id !== null && in_array('bpo', $assignment->role->panels, true));
    }

    public function canAccessPanel(User $user): bool
    {
        return $this->companies($user)->isNotEmpty();
    }
}
