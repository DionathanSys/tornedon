<?php

namespace App\Services\Fiscal;

use App\Models\FiscalProfile;
use Filament\Facades\Filament;

class FiscalProfileService
{
    /**
     * Get the fiscal profile for a specific company or the current tenant.
     */
    public function getFiscalProfile(?int $companyId = null): ?FiscalProfile
    {
        $companyId = $companyId ?? Filament::getTenant()?->id;

        if (! $companyId) {
            return null;
        }

        return FiscalProfile::where('company_id', $companyId)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get the default NBS code from the fiscal profile.
     */
    public function getDefaultNbsCode(?int $companyId = null): ?string
    {
        $profile = $this->getFiscalProfile($companyId);

        return $profile?->default_nbs_code;
    }

    /**
     * Get the default service CNAE code from the fiscal profile.
     */
    public function getDefaultCnaeCode(?int $companyId = null): ?string
    {
        $profile = $this->getFiscalProfile($companyId);

        return $profile?->service_cnae_code;
    }

    /**
     * Get the default municipal tax code from the fiscal profile.
     */
    public function getDefaultMunicipalTaxCode(?int $companyId = null): ?string
    {
        $profile = $this->getFiscalProfile($companyId);

        return $profile?->default_municipal_tax_code;
    }

    /**
     * Get the default ISS rate from the fiscal profile.
     */
    public function getDefaultIssRate(?int $companyId = null): ?float
    {
        $profile = $this->getFiscalProfile($companyId);

        return $profile?->iss_rate_default;
    }
}
