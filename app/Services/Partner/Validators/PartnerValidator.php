<?php

namespace App\Services\Partner\Validators;

use App\Enum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PartnerValidator
{
    /**
     * Valida dados de Partner
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validate(array $data, ?int $partnerId = null): array
    {
        $rules = [
            'name'                  => 'required|string|max:60',
            'document_type'         => 'required|string|in:cnpj,cpf',
            'document_number'       => [
                'required',
                'string',
                Rule::unique('partners', 'document_number')->ignore($partnerId ?? null),
                function ($attribute, $value, $fail) use ($data) {
                    if (($data['document_type'] ?? null) === 'cpf' && strlen($value) !== 14) {
                        $fail('O CPF deve conter exatamente 14 caracteres.');
                    }

                    if (($data['document_type'] ?? null) === 'cnpj' && strlen($value) !== 18) {
                        $fail('O CNPJ deve conter exatamente 18 caracteres.');
                    }
                },
            ],
            'state_tax_id'          => 'nullable|integer',
            'state_tax_indicator'   => 'required|int|in:' . implode(',', array_map(fn($case) => $case->value, Enum\Tax\StateTaxIndicator::cases())),
            'municipal_tax_id'      => 'nullable|integer',
        ];
        
        $messages = [
            'name.required'             => 'O nome do parceiro é obrigatório.',
            'name.max'                  => 'O nome do parceiro deve ter no máximo 60 caracteres.',
            'document_type.in'          => 'O tipo de documento informado é inválido.',
            'document_type.required'    => 'O tipo de documento é obrigatório.',
            'document_number.required'  => 'O número do documento é obrigatório.',
            'document_number.unique'    => 'Este documento já está cadastrado.',
            'state_tax_indicator.in'    => 'O indicador de inscrição estadual informado é inválido.',
            'state_tax_indicator.required'=> 'O indicador de inscrição estadual é obrigatório.',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
