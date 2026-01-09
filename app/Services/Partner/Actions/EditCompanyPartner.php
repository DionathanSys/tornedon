<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Models\CompanyPartner;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class EditCompanyPartner
{
    use HandlesActionResponse;

    private array $fillableFields;

    public function __construct(protected CompanyPartner $companyPartner)
    {
        $this->fillableFields = (new CompanyPartner())->getFillable();
    }

    public function execute(array $data): ?CompanyPartner
    {
        $this->validate($data);

        if ($this->hasError()) {
            return null;
        }

        $data = Arr::only($data, $this->fillableFields);
        
        $this->companyPartner->update($data);

        $this->setSuccess();
        return $this->companyPartner;
    }

    private function validate(array $data): void
    {
        $validate = Validator::make($data, [
            'type'              => 'required|array|min:1',
            'type.*'            => 'required|string|min:1|in:' . implode(',', array_map(fn($case) => $case->value, Enum\Partner\Type::cases())),
            'invoice_threshold' => 'required|numeric|min:0|max:99999999',
            'is_active'         => 'required|boolean',
        ], [
            'type.required'                 => 'O tipo de vínculo com o parceiro é obrigatório.',
            'type.*.in'                     => 'Tipo de vínculo inválido.',
            'invoice_threshold.required'    => 'É obrigatório definir valor mín. para faturamento.',
            'invoice_threshold.min'         => 'Valor para faturamento mín. é de R$ 0,00 ',
            'invoice_threshold.max'         => 'Valor para faturamento máx. é de R$ 99.999.999,00',
            'is_active.required'            => 'É obrigatório definir o status como Ativo/Inativo.',
            'is_active.boolean'             => 'Valor inválido para o campo Ativo.',
        ]);

        if ($validate->fails()) {
            $this->setError('Falha de validação dos dados', $validate->errors()->toArray());
            Log::error(__METHOD__ . '@' . __LINE__, [
                'message'   => 'Falha de validação dos dados',
                'errors'    => $validate->errors()->toArray(),
                'data'      => $data,
            ]);
            return;
        }

        return;
    }
}
