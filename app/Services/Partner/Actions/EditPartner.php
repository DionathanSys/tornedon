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

    private array $fillableFields;

    public function __construct(protected Partner $partner)
    {
        $this->fillableFields = (new Partner())->getFillable();
    }

    public function execute(array $data): ?Partner
    {
        try {
            $validator = new PartnerValidator();
            $validatedData = $validator->validateForUpdate($data, $this->partner->id);
            
            $validatedData = Arr::only($validatedData, $this->fillableFields);
            $this->partner->update($validatedData);
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

