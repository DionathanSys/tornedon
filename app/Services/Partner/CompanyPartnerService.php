<?php

namespace App\Services\Partner;

use App\Models\CompanyPartner;
use App\Traits\HandlesServiceResponse;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Log;

class CompanyPartnerService
{
    use HandlesServiceResponse;

    public function update(CompanyPartner $companyPartner, array $data)
    {
        try {
            $action = new Actions\EditCompanyPartner($companyPartner);
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message'           => 'Erro identificado durante execução da Action para edição do vínculo entre Empresa e Parceiro',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Vínculo com parceiro atualizado');
            return $result;
        } catch (\Exception $e) {
            $this->setError('Erro ao atualizar vínculo entre Empresa e Parceiro', $action->getErrors());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao atualizar vínculo entre Empresa e Parceiro',
                'errors' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function companyHasPartner(int $parnerId, int|null $companyId = null): ?CompanyPartner
    {
        $companyId = $companyId ?? (Filament::getTenant())->id;

        if ($companyId == null) {
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
