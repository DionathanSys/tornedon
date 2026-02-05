<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Models\CompanyPartner;
use App\Services\Partner\Validators\CompanyPartnerValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AssociatePartnerCompany
{
    use HandlesActionResponse;

    public function execute(int $partnerId, int $companyId, array $data): ?CompanyPartner
    {
        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message' => 'Iniciando associação de parceiro com empresa',
            'partner_id' => $partnerId,
            'company_id' => $companyId,
            'data' => $data,
        ]);

        $validatedData = CompanyPartnerValidator::validate($data, $partnerId);

        $existing = CompanyPartner::query()
            ->where('partner_id', $partnerId)
            ->where('company_id', $companyId)
            ->first();

        if ($existing) {
            Log::info(__METHOD__ . '@' . __LINE__, [
                'message' => 'Partner já associado a esta empresa',
                'company_partner_id' => $existing->id,
            ]);

            $this->setSuccess();
            return $existing;
        }

        $data = array_merge($validatedData, [
            'partner_id' => $partnerId,
            'company_id' => $companyId,
        ]);

        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message' => 'Criando nova associação de parceiro com empresa',
            'data' => $data,
        ]);

        $companyPartner = CompanyPartner::create($data);

        $this->setSuccess();
        return $companyPartner;
    }

}
