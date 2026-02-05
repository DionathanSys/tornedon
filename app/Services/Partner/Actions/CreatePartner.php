<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Services\Partner\Validators\PartnerValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreatePartner
{
    use HandlesActionResponse;

    public function __construct(
        private int $createdBy,
    ) {}

    public function execute(array $data): ?Partner
    {
        try {
            $validatedData = PartnerValidator::validate($data);
            
            $data = array_merge($validatedData, [
                'created_by' => $this->createdBy,
            ]);

            $partner = Partner::create($data);
            $this->setSuccess();
            return $partner;
            
        } catch (ValidationException $e) {
            $this->setError('Falha de validação dos dados', $e->errors());
            
            Log::error(__METHOD__ . '@' . __LINE__, [
                'error_code' => $this->getErrorCode(),
                'message'    => 'Falha de validação dos dados',
                'errors'     => $e->errors(),
                'data'       => $data,
            ]);
            
            return null;
        }
    }

}

