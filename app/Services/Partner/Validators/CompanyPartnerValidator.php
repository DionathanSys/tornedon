<?php

namespace App\Services\Partner\Validators;

use App\Enum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CompanyPartnerValidator
{
    /**
     * Valida dados de Company Partner
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validate(array $data): array
    {
        
        $rules = [
            'type'              => 'required|array|min:1',
            'type.*'            => 'required|string|min:1|in:' . implode(',', array_map(fn($case) => $case->value, Enum\Partner\Type::cases())),
            'invoice_threshold' => 'required|numeric|min:0|max:99999999',
            'is_active'         => 'required|boolean',
        ];
        
        $messages = [
            'type.required'                 => 'O tipo de vínculo com o parceiro é obrigatório.',
            'type.*.in'                     => 'Tipo de vínculo inválido.',
            'invoice_threshold.required'    => 'É obrigatório definir valor mín. para faturamento.',
            'invoice_threshold.min'         => 'Valor para faturamento mín. é de R$ 0,00 ',
            'invoice_threshold.max'         => 'Valor para faturamento máx. é de R$ 99.999.999,00',
            'is_active.required'            => 'É obrigatório definir o status como Ativo/Inativo.',
            'is_active.boolean'             => 'Valor inválido para o campo Ativo.',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
