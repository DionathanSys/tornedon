<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Models\CompanyPartner;
use App\Services\Partner\Validators\CompanyPartnerValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

        $validatedData = CompanyPartnerValidator::validate($data);

        $data = array_merge($validatedData, [
            'partner_id' => $partnerId,
            'company_id' => $companyId,
        ]);

        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message' => 'Dados preparados para criação de associação',
            'data' => $data,
        ]);

        $companyPartner = CompanyPartner::create($data);

        $this->setSuccess('Parceiro associado à empresa com sucesso');
        return $companyPartner;

    }
}
