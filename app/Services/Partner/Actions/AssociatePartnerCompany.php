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

        $existing = CompanyPartner::query()
            ->where('partner_id', $partnerId)
            ->where('company_id', $companyId)
            ->first();

        if ($existing) {
            Log::info(__METHOD__ . '@' . __LINE__, [
                'message' => 'Partner já associado a esta empresa',
                'company_partner_id' => $existing->id,
            ]);

            $this->setSuccess();
            return $existing;
        }

        $companyPartner = CompanyPartner::create([
            'company_id' => $companyId,
            'partner_id' => $partnerId,
            'type' => $data['type'],
            'invoice_threshold' => $data['invoice_threshold'] ?? 0,
            'is_active' => $data['is_active'],
        ]);

        $this->setSuccess();
        return $companyPartner;
    }

    private function validate(array $data): void
    {
        $validate = Validator::make(
            $data,
            [
                'type'   => 'required|array|min:1',
                'type.*' => 'required|string|in:' . implode(',', array_map(fn($case) => $case->value, Enum\Partner\Type::cases())),
                'invoice_threshold' => 'nullable|numeric|min:0',
                'is_active' => 'boolean',
            ],
            [
                'type.required' => 'O tipo de parceiro é obrigatório.',
                'type.*.in'     => 'O tipo de parceiro informado é inválido.',
                'invoice_threshold.numeric' => 'O limite de faturamento deve ser um número.',
                'invoice_threshold.min'     => 'O limite de faturamento não pode ser negativo.',
                'is_active.boolean'         => 'O campo ativo deve ser verdadeiro ou falso.',
            ]
        );

        if ($validate->fails()) {
            $this->setError('Falha de validação dos dados', $validate->errors()->all());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'   => 'Falha de validação dos dados',
                'errors'    => $validate->errors()->toArray(),
            ]);
            return;
        }

        return;
    }
}
