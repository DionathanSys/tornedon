<?php

namespace App\Services\Partner\Validators;

use App\Enum;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanyPartnerValidator
{
    /**
     * Valida dados de Company Partner
     *
     * @param  array  $data  Dados a validar
     * @return array Retorna dados validados
     *
     * @throws ValidationException Se a validacao falhar
     */
    public static function validate(array $data): array
    {
        $rules = [
            'type' => 'required|array|min:1',
            'type.*' => 'required|string|min:1|in:' . implode(',', array_map(fn ($case) => $case->value, Enum\Partner\Type::cases())),
            'invoice_threshold' => 'required|numeric|min:0|max:99999999',
            'customer_discount_percentage' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'required|boolean',
            'notify_service_order_closed' => 'sometimes|boolean',
            'notify_requisition_closed' => 'sometimes|boolean',
            'notify_production_order_closed' => 'sometimes|boolean',
            'notify_invoice_confirmed' => 'sometimes|boolean',
            'notify_fiscal_document_confirmed' => 'sometimes|boolean',
            'email_to_override' => 'nullable|string',
            'email_cc_override' => 'nullable|string',
            'email_bcc_override' => 'nullable|string',
        ];

        $messages = [
            'type.required' => 'O tipo de vinculo com o parceiro e obrigatorio.',
            'type.*.in' => 'Tipo de vinculo invalido.',
            'invoice_threshold.required' => 'E obrigatorio definir valor min. para faturamento.',
            'invoice_threshold.min' => 'Valor para faturamento min. e de R$ 0,00.',
            'invoice_threshold.max' => 'Valor para faturamento max. e de R$ 99.999.999,00.',
            'customer_discount_percentage.numeric' => 'O desconto do cliente deve ser um numero.',
            'customer_discount_percentage.min' => 'O desconto do cliente nao pode ser negativo.',
            'customer_discount_percentage.max' => 'O desconto do cliente nao pode ser maior que 100%.',
            'is_active.required' => 'E obrigatorio definir o status como Ativo/Inativo.',
            'is_active.boolean' => 'Valor invalido para o campo Ativo.',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
