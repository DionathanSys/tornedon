<?php

namespace App\Services\Address;

use App\Exceptions\DomainValidationException;
use App\Models\Address;
// use App\Services\Address\Actions;
use App\Traits\HandlesServiceResponse;
use DomainException;
use Illuminate\Support\Facades\Log;

class AddressService
{

    use HandlesServiceResponse;

    public function create2(array $data, int $userId): ?Address
    {
        try {

            $action = new Actions\CreateAddress($userId);
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'message'           => 'Erro identificado durante execução da Action para cadastro de endereço',
                    'action_message'    => $action->getMessage(),
                    'errors'            => $action->getErrors(),
                ]);
                return null;
            }

            $this->setSuccess('Endereço cadastrado com sucesso');
            return $result;
        } catch (\Exception $e) {
            $this->setError('Erro ao cadastrar endereço', $action->getErrors());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message' => 'Erro ao cadastrar endereço',
                'errors'  => $e->getMessage(),
                'data'    => $data,
            ]);
            return null;
        }
    }

    public function create(
        int $companyId,
        int $partnerId,
        array $input,
        int $userId
    ): ?Address {
        try {
            $action = new Actions\CreateAddressAction(
                companyId: $companyId,
                partnerId: $partnerId,
                createdBy: $userId
            );

            $address = $action->execute($input);

            $this->setSuccess('Endereço cadastrado com sucesso');

            return $address;
        } catch (DomainValidationException $e) {
            $this->setError('Falha ao salvar endereço', $e->errors);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'company_id'    => $companyId,
                'partner_id'    => $partnerId,
                'user_id'       => $userId,
                'validation_errors' => $e->errors,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao cadastrar endereço');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'company_id'    => $companyId,
                'partner_id'    => $partnerId,
                'user_id'       => $userId,
                'exception'     => $e,
            ]);
            return null;
        }
    }

    public function update(Address $address, $data): ?Address
    {

        return null;
    }
}
