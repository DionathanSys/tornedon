<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Services\Partner\Validators\PartnerValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EditPartner
{
    use HandlesActionResponse;

    public function __construct(protected int $updatedBy, protected Partner $partner)
    {
    }

    public function execute(array $data): ?Partner
    {
        try {
            $validatedData = PartnerValidator::validate($data, $this->partner->id);
            
            $data = array_merge($validatedData, [
                'updated_by' => $this->updatedBy,
            ]);

            $this->partner->update($data);
            $this->setSuccess();
            return $this->partner;
            
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

