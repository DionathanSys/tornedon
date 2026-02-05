<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Models\CompanyPartner;
use App\Services\Partner\Validators\CompanyPartnerValidator;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class EditCompanyPartner
{
    use HandlesActionResponse;

    public function __construct(protected CompanyPartner $companyPartner)
    {
    }

    public function execute(array $data): ?CompanyPartner
    {
        $validatedData = CompanyPartnerValidator::validate($data);

        $this->companyPartner->update($validatedData);

        $this->setSuccess();
        return $this->companyPartner;
    }

}
