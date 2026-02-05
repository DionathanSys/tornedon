<?php

namespace App\Services\Partner;

use App\Models\CompanyPartner;
use App\Models\Partner;
use App\Enum;
use App\Services\Partner\Actions\AssociatePartnerCompany;
use App\Services\Partner\Actions\EditPartner;
use App\Traits\HandlesServiceResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PartnerService
{
    use HandlesServiceResponse;

    public function createPartner(int $createdBy, array $data): ?Partner
    {
        try {
            $action = new Actions\CreatePartner($createdBy);
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'error_code'        => $this->getErrorCode(),
                    'message'           => 'Erro identificado durante execução da Action para criação do Parceiro',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            ds( $result )->label('Parceiro criado com sucesso' );
            $this->setSuccess('Parceiro cadastrado com sucesso');
            return $result;
        } catch (\Exception $e) {
            $this->setError('Erro ao cadastrar parceiro', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro ao cadastrar parceiro',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);
            return null;
        }
    }

    public function associatePartnerCompany(int $partnerId, int $companyId, array $data): ?CompanyPartner
    {
        try {

            $action = new AssociatePartnerCompany();
            $result = $action->execute($partnerId, $companyId, $data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors(), 422, $action->getErrorCode());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'error_code'        => $action->getErrorCode(),
                    'message'           => 'Erro identificado durante execução da Action para associação do Parceiro com Empresa',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Parceiro Associado com sucesso');
            return $result;
        } catch (\Exception $e) {
            $this->setError('Erro ao vincular parceiro e empresa', [$e->getMessage()], 500);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro ao vincular parceiro e empresa',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);
            return null;
        }
    }

    public function editPartner(int $updatedBy, Partner $partner, array $data): ?Partner
    {
        try {
            $action = new EditPartner($updatedBy, $partner);
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'error_code'        => $action->getErrorCode(),
                    'message'           => 'Erro identificado durante execução da Action para edição do Parceiro',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess();
            return $result;
        } catch (\Exception $e) {
            $errorCode = $this->generateErrorCode();
            $this->setError('Erro ao editar parceiro', [$e->getMessage()], 500, $errorCode);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $errorCode,
                'message'    => 'Erro ao editar parceiro',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'data'       => $data,
            ]);
            return null;
        }
    }

    /**
     * Busca partner existente por documento ou cria novo
     */
    public function findOrCreatePartner(int $createdBy, array $data): ?Partner
    {
        try {
            $existing = Partner::where('document_number', $data['document_number'])->first();

            if ($existing) {
                Log::info(__METHOD__ . '@' . __LINE__, [
                    'message' => 'Parceiro existente encontrado, reutilizando',
                    'partner_id' => $existing->id,
                    'document_number' => $data['document_number'],
                ]);

                $this->setSuccess('Parceiro encontrado');
                return $existing;
            }

            return $this->createPartner($createdBy, $data);
        } catch (\Exception $e) {
            $this->setError('Erro ao buscar/criar parceiro', [$e->getMessage()]);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message' => 'Erro ao buscar/criar parceiro',
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    public function getPartnerByDocument(string $documentNumber): ?Partner
    {
        if (Str::length($documentNumber) != 14 && Str::length($documentNumber) != 18) {
            $this->setError('Nro. de documento inválido');
            return null;
        }

        $result = Partner::query()
            ->where('document_number', $documentNumber)
            ->get()
            ->first();

        if (!$result) {
            $this->setError('Parceiro não encontrado');
            return null;
        }

        $this->setSuccess();
        return $result;
    }

    public function getPartnerById(int $partnerId): ?Partner
    {
        $result = Partner::query()
            ->find($partnerId);

        if (!$result) {
            $this->setError('Parceiro não encontrado');
            return null;
        }

        $this->setSuccess();
        return $result;
    }

    public function getPartnersByCompanyId(int $companyId): ?Collection
    {
        return Partner::query()
            ->whereHas('companies', function ($query) use ($companyId) {
                $query->where('company_id', $companyId);
            })
            ->get();
    }
}
