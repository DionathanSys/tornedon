<?php

namespace App\Services\Contact;

use App\Exceptions\DomainValidationException;
use App\Models\Contact;
use App\Services\Contact\Actions\CreateContact;
use App\Services\Contact\Actions\UpdateContact;
use App\Services\Contact\Actions\DeleteContact;
use App\Traits\HandlesServiceResponse;
use Illuminate\Support\Facades\Log;

class ContactService
{
    use HandlesServiceResponse;

    /**
     * Cria um novo contato
     * 
     * @param int $companyPartnerId ID do CompanyPartner
     * @param array $data Dados do contato
     * @param int $userId ID do usuário que está criando
     * @return Contact|null
     */
    public function create(int $companyPartnerId, array $data, int $userId): ?Contact
    {
        try {
            $action = new CreateContact($companyPartnerId, $userId);
            $contact = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'error_code'         => $this->getErrorCode(),
                    'message'            => 'Erro identificado durante execução da Action para criação do Contato',
                    'action_message'     => $action->getMessage(),
                    'errors'             => $action->getErrors(),
                    'company_partner_id' => $companyPartnerId,
                ]);
                return null;
            }

            Log::info('Contato cadastrado com sucesso', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'contact_id'         => $contact->id,
                'company_partner_id' => $companyPartnerId,
                'user_id'            => $userId,
            ]);

            $this->setSuccess('Contato cadastrado com sucesso');
            return $contact;
            
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao cadastrar contato');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code'         => $this->getErrorCode(),
                'message'            => 'Erro interno ao cadastrar contato',
                'exception'          => $e->getMessage(),
                'trace'              => $e->getTraceAsString(),
                'company_partner_id' => $companyPartnerId,
            ]);
            return null;
        }
    }

    /**
     * Atualiza um contato existente
     * 
     * @param Contact $contact Contato a ser atualizado
     * @param array $data Dados do contato
     * @param int $userId ID do usuário que está atualizando
     * @return Contact|null
     */
    public function update(Contact $contact, array $data, int $userId): ?Contact
    {
        try {
            $action = new UpdateContact($contact, $userId);
            $result = $action->execute($data);

            if ($action->hasError()) {
                $this->setError($action->getMessage(), $action->getErrors());
                Log::error(__METHOD__ . '@' . __LINE__, [
                    'error_code'     => $this->getErrorCode(),
                    'message'        => 'Erro identificado durante execução da Action para atualização do Contato',
                    'action_message' => $action->getMessage(),
                    'errors'         => $action->getErrors(),
                    'contact_id'     => $contact->id,
                ]);
                return null;
            }

            Log::info('Contato atualizado com sucesso', [
                'metodo'     => __METHOD__ . '@' . __LINE__,
                'contact_id' => $contact->id,
                'user_id'    => $userId,
            ]);

            $this->setSuccess('Contato atualizado com sucesso');
            return $result;
            
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao atualizar contato');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro interno ao atualizar contato',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'contact_id' => $contact->id,
            ]);
            return null;
        }
    }

    /**
     * Deleta um contato
     * 
     * @param Contact $contact Contato a ser deletado
     * @param int $userId ID do usuário que está deletando
     * @return bool
     */
    public function delete(Contact $contact, int $userId): bool
    {
        try {
            $action = new DeleteContact($contact, $userId);
            $result = $action->execute();

            Log::info('Contato excluído com sucesso via service', [
                'metodo'             => __METHOD__ . '@' . __LINE__,
                'contact_id'         => $contact->id,
                'company_partner_id' => $contact->company_partner_id,
                'user_id'            => $userId,
            ]);

            $this->setSuccess('Contato excluído com sucesso');
            return $result;
            
        } catch (DomainValidationException $e) {
            $this->setError('Falha durante exclusão do contato', $e->errors);
            Log::error(__METHOD__ . '@' . __LINE__, [
                'contact_id'        => $contact->id,
                'user_id'           => $userId,
                'validation_errors' => $e->errors,
            ]);
            return false;
            
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao excluir contato');
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro interno ao excluir contato',
                'exception'  => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
                'contact_id' => $contact->id,
            ]);
            return false;
        }
    }
}
