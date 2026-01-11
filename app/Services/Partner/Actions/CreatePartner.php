<?php

namespace App\Services\Partner\Actions;

use App\Models\Partner;
use App\Enum;
use App\Traits\HandlesActionResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CreatePartner
{
    use HandlesActionResponse;

    private array $fillableFields;

    public function __construct()
    {
        $this->fillableFields = (new Partner())->getFillable();
    }

    public function execute(array $data): ?Partner
    {
        $this->validate($data);

        if ($this->hasError()) return null;

        $partner = $this->exists($data);
        if ($partner) {
            $this->setSuccess();
            Log::info(__METHOD__ . '@' . __LINE__, [
                'message'           => 'Parceiro já cadastrado',
                'document_number'   => $data['document_number'],
            ]);
            return $partner;
        }

        $data = Arr::only($data, $this->fillableFields);
        $partner = Partner::create($data);
        $this->setSuccess();
        return $partner;
    }

    private function exists(array $data): ?Partner
    {
        return Partner::where('document_number', $data['document_number'])->first();
    }

    private function validate(array $data): void
    {
        $validate = Validator::make($data, [
            'name'                  => 'required|string|max:255',
            'document_type'         => 'required|string|in:cnpj,cpf',
            'document_number'       => [
                'required',
                'string',
                function ($attribute, $value, $fail) use ($data) {
                    if (($data['document_type'] ?? null) === 'cpf' && strlen($value) !== 14) {
                        $fail('O CPF deve conter exatamente 14 caracteres.');
                    }

                    if (($data['document_type'] ?? null) === 'cnpj' && strlen($value) !== 18) {
                        $fail('O CNPJ deve conter exatamente 18 caracteres.');
                    }
                },
            ],
            'state_tax_id'          => 'nullable|string|max:50',
            'state_tax_indicator'   => 'nullable|int|in:' . implode(',', array_map(fn($case) => $case->value, Enum\Tax\StateTaxIndicator::cases())),
            'municipal_tax_id'      => 'nullable|string|max:50',
            'created_by'            => 'required|integer|exists:users,id',
        ], [
            'name.required'             => 'O nome do parceiro é obrigatório.',
            'document_type.in'          => 'O tipo de documento informado é inválido.',
            'document_number.required'  => 'O número do documento é obrigatório.',
            'state_tax_id.max'          => 'A inscrição estadual deve ter no máximo 50 caracteres.',
            'municipal_tax_id.max'      => 'A inscrição municipal deve ter no máximo 50 caracteres.',
            'state_tax_indicator.in'    => 'O indicador de inscrição estadual informado é inválido.',
            'created_by.required'       => 'O usuário criador é obrigatório.',
            'created_by.exists'         => 'O usuário criador informado não existe.',
        ]);

        if ($validate->fails()) {
            $this->setError('Falha de validação dos dados', $validate->errors()->all());
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
