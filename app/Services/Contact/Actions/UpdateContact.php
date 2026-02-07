<?php

namespace App\Services\Contact\Actions;

use App\Models\Contact;
use App\Services\Contact\Validators\ContactValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UpdateContact
{
    use HandlesActionResponse;

    public function __construct(
        private Contact $contact,
        private int $updatedBy,
    ) {}

    public function execute(array $data): ?Contact
    {
        try {
            $validatedData = ContactValidator::validate(
                $data, 
                $this->contact->company_partner_id,
                $this->contact->id
            );
            
            $contactData = array_merge($validatedData, [
                'updated_by' => $this->updatedBy,
            ]);

            $this->contact->update($contactData);
            
            $this->setSuccess();
            return $this->contact;
            
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Falha de validação dos dados ao atualizar contato',
                'errors'     => $e->errors(),
                'contact_id' => $this->contact->id,
                'data'       => $data,
            ]);
            
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao atualizar contato');
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Erro interno ao atualizar contato',
                'exception'  => $e->getMessage(),
                'contact_id' => $this->contact->id,
            ]);
            
            return null;
        }
    }
}
