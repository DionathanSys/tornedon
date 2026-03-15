<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Models\CompanyPartner;
use App\Services\Partner\Validators\CompanyPartnerValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Database\QueryException;
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

        $validatedData = CompanyPartnerValidator::validate($data);

        $data = array_merge($validatedData, [
            'partner_id' => $partnerId,
            'company_id' => $companyId,
        ]);

        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message' => 'Criando/atualizando associação de parceiro com empresa',
            'data' => $data,
        ]);

        try {
            CompanyPartner::query()->upsert(
                [$data],
                ['company_id', 'partner_id'],
                array_keys($validatedData)
            );
        } catch (QueryException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }

            Log::warning(__METHOD__ . '@' . __LINE__, [
                'message' => 'Conflito de chave única ao associar parceiro e empresa; buscando registro existente',
                'partner_id' => $partnerId,
                'company_id' => $companyId,
                'exception' => $e->getMessage(),
            ]);
        }

        $companyPartner = CompanyPartner::query()
            ->where('partner_id', $partnerId)
            ->where('company_id', $companyId)
            ->first();

        if (! $companyPartner) {
            throw new \RuntimeException('Não foi possível localizar o vínculo company_partner após o upsert.');
        }

        $this->setSuccess();
        return $companyPartner;
    }

}
