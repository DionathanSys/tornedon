<?php

namespace App\Services\Partner;

use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Enum;
use App\Services\Partner\Actions\AssociatePartnerCompany;
use App\Services\Partner\Actions\EditPartner;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PartnerService
{
    use HandlesServiceResponse;

    public function createPartner(array $data): ?Partner
    {
        try {
            $action = new Actions\CreatePartner();
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message'           => 'Erro identificado durante execução da Action para edição do Parceiro',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Parceiro cadastrado com sucesso');
            return $result;
        } catch (\Exception $e) {
            $this->setError('Erro ao cadastrar parceiro', $action->getErrors());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao cadastrar parceiro',
                'errors' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function associatePartnerCompany(int $partnerId, int $companyId, array $data): ?CompanyPartner
    {
        try {
            $action = new AssociatePartnerCompany();
            $result = $action->execute($partnerId, $companyId, $data);

            if($action->hasError()){
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message'           => 'Erro identificado durante execução da Action para associação do Parceiro com Empresa',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Parceiro Associado com sucesso');
            return $result;

         } catch (\Exception $e) {
            $this->setError('Erro ao vincular parceiro e empresa');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'   => 'Erro ao vincular parceiro e empresa',
                'errors'    => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function editPartner(array $data): ?Partner
    {
        try {
            $action = new EditPartner();
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message'           => 'Erro identificado durante execução da Action para edição do Parceiro',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess();
            return $result;
        } catch (\Exception $e) {
            $this->setError('Erro ao editar parceiro');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao editar parceiro',
                'errors' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function getPartner(string $documentNumber): ?Partner
    {
        if(Str::length($documentNumber) != 14 && Str::length($documentNumber) != 18){
            $this->setError('Nro. de documento inválido');
            return null;
        }

        $result = Partner::query()
            ->where('document_number', $documentNumber)
            ->get()
            ->first();

        if(!$result){
            $this->setError('Parceiro não encontrado');
            return null;
        }

        return $result;
    }
}
