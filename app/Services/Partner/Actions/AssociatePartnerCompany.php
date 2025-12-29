<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Models\CompanyPartner;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AssociatePartnerCompany
{
    use HandlesActionResponse;

    public function execute(int $partnerId, int $companyId, array $data): ?CompanyPartner
    {
        $this->validate($data);

        if ($this->hasError()) return null;

        $companyPartner = CompanyPartner::updateOrCreate(
                [
                    'company_id' => $companyId,
                    'partner_id' => $partnerId,
                ],
                [
                    'type' => $data['type'],
                    'invoice_threshold' => $data['invoice_threshold'] ?? 0,
                    'is_active' => $data['is_active'] ?? true,
                ]
            );
        $this->setSuccess();
        return $companyPartner;
    }

    private function validate(array $data): void
    {
        //TODO: Validar campo invoice_threshold
        $validate = Validator::make(
            $data,
            [
                'type'   => 'required|array|min:1',
                'type.*' => 'required|string|in:' . implode(',', array_map(fn($case) => $case->value, Enum\Partner\Type::cases())),
            ],
            [
                'type.required' => 'O tipo de parceiro é obrigatório.',
                'type.*.in'     => 'O tipo de parceiro informado é inválido.',
            ]
        );

        if ($validate->fails()) {
            $this->setError('Falha de validação dos dados', $validate->errors()->toArray());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'   => 'Falha de validação dos dados',
                'errors'    => $validate->errors()->toArray(),
            ]);
            return;
        }

        return;
    }
}
