<?php

namespace App\Services\Contact\Actions;

use App\Models\Contact;
use App\Services\Contact\Validators\ContactValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateContact
{
    use HandlesActionResponse;

    public function __construct(
        private int $companyPartnerId,
        private int $createdBy,
    ) {}

    public function execute(array $data): ?Contact
    {
        try {
            $validatedData = ContactValidator::validate($data, $this->companyPartnerId);
            
            $contactData = array_merge($validatedData, [
                'company_partner_id' => $this->companyPartnerId,
                'created_by' => $this->createdBy,
            ]);

            $contact = Contact::create($contactData);
            
            $this->setSuccess();
            return $contact;
            
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code'         => $this->getErrorCode(),
                'message'            => 'Falha de validação dos dados ao criar contato',
                'errors'             => $e->errors(),
                'company_partner_id' => $this->companyPartnerId,
                'data'               => $data,
            ]);
            
            return null;
        } catch (\Throwable $e) {
            $this->setError('Erro interno ao criar contato');
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code'         => $this->getErrorCode(),
                'message'            => 'Erro interno ao criar contato',
                'exception'          => $e->getMessage(),
                'company_partner_id' => $this->companyPartnerId,
            ]);
            
            return null;
        }
    }
}
