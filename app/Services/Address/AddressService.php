<?php

namespace App\Services\Address;

use App\Exceptions\DomainValidationException;
use App\Models\Address;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class AddressService
{

    use HandlesServiceResponse;

    public function create(int $companyPartnerId, int $companyId, int $partnerId, array $input, int $userId): ?Address 
    {
        try {
            $action = new Actions\CreateAddress($companyPartnerId, $companyId, $partnerId, $userId);

            $address = $action->execute($input);

            Log::info('Endereço cadastrado com sucesso', [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'address_id'    => $address->id,
                'company_id'    => $companyId,
                'partner_id'    => $partnerId,
                'user_id'       => $userId,
            ]);

            $this->setSuccess('Endereço cadastrado com sucesso');

            return $address;
        } catch (DomainValidationException $e) {
            $this->setError('Falha durante cadastro do endereço', $e->errors);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'company_partner_id'    => $companyPartnerId,
                'company_id'        => $companyId,
                'partner_id'        => $partnerId,
                'user_id'           => $userId,
                'validation_errors' => $e->errors,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao cadastrar endereço');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'company_partner_id'    => $companyPartnerId,
                'company_id'    => $companyId,
                'partner_id'    => $partnerId,
                'user_id'       => $userId,
                'exception'     => $e,
            ]);
            return null;
        }
    }

    public function update(Address $address, $input, int $userId): ?Address
    {
        try {
            $action = new Actions\UpdateAddress($address, $userId);
            $result = $action->execute($input);

            $this->setSuccess('Endereço atualizado com sucesso');

            return $result;
        } catch(DomainValidationException $e) {
            $this->setError('Falha durante atualização do endereço', $e->errors);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'address_id'    => $address->id,
                'user_id'       => $userId,
                'validation_errors' => $e->errors,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao atualizar endereço');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'address_id'    => $address->id,
                'user_id'       => $userId,
                'exception'     => $e,
            ]);
            return null;
        }
    }

    public function delete(Address $address, int $userId): bool
    {
        try {
            $action = new Actions\DeleteAddress($address, $userId);
            $result = $action->execute();

            Log::info('Endereço excluído com sucesso via service', [
                'metodo'        => __METHOD__ . '@' . __LINE__,
                'address_id'    => $address->id,
                'partner_id'    => $address->partner_id,
                'company_id'    => $address->company_id,
                'user_id'       => $userId,
            ]);

            $this->setSuccess('Endereço excluído com sucesso');

            return $result;
        } catch (DomainValidationException $e) {
            $this->setError('Falha durante exclusão do endereço', $e->errors);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'address_id'        => $address->id,
                'user_id'           => $userId,
                'validation_errors' => $e->errors,
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao excluir endereço');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'address_id'    => $address->id,
                'user_id'       => $userId,
                'exception'     => $e,
            ]);
            return false;
        }
    }
}
