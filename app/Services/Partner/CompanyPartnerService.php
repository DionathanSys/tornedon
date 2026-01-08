<?php

namespace App\Services\Partner;

use App\Models\CompanyPartner;
use App\Traits\HandlesServiceResponse;
use Filament\Facades\Filament;

class CompanyPartnerService
{
    use HandlesServiceResponse;

    public static function companyHasPartner(int $parnerId, int|null $companyId = null): ? CompanyPartner
    {
        $companyId = $companyId ?? (Filament::getTenant())->id;

        if($companyId == null){
            (new self)->setError('Empresa não informada ou não autenticada!');
            return null;
        }

        return CompanyPartner::query()
            ->where('company_id', $companyId)
            ->where('partner_id', $parnerId)
            ->get()
            ->first();
    }
}