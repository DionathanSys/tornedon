<?php

namespace App\Services\ProductionOrder\Validators;

use App\Enum\ProductionOrder\DestinationType;
use App\Enum\ProductionOrder\Priority;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductionOrderValidator
{
    /**
     * Regras comuns de validação (campos compartilhados entre create e update).
     */
    private static function commonRules(array $data = []): array
    {
        return [
            'quote_id'          => 'nullable|integer|exists:quotes,id',
            'observations'      => 'nullable|string',
            'assigned_operator' => 'nullable|integer|exists:users,id',
            'assigned_machine'  => 'nullable|string',
            'priority'          => ['required', Rule::enum(Priority::class)],
            'destination_type'  => ['required', Rule::enum(DestinationType::class)],
            'customer_id'       => [
                'nullable',
                'integer',
                'exists:partners,id',
                Rule::requiredIf(
                    fn () => ($data['destination_type'] ?? null) === DestinationType::DIRECT_DELIVERY->value
                ),
            ],
        ];
    }

    /**
     * Mensagens de validação compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'company_id.required'       => 'A empresa é obrigatória.',
            'company_id.exists'         => 'Empresa não encontrada.',
            'customer_id.required'       => 'O cliente é obrigatório para destino Entrega Direta.',
            'customer_id.exists'         => 'Cliente não encontrado.',
            'quote_id.exists'           => 'Orçamento não encontrado.',
            'priority.required'         => 'A prioridade é obrigatória.',
            'priority.in'               => 'Prioridade inválida.',
            'destination_type.required' => 'O tipo de destino é obrigatório.',
            'destination_type.in'       => 'Tipo de destino inválido.',
            'assigned_operator.exists'  => 'Operador designado não encontrado.',
        ];
    }

    /**
     * Valida dados para criação de ordem de produção.
     *
     * @param  array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = array_merge(self::commonRules($data), [
            'company_id'        => 'required|integer|exists:companies,id',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualização de ordem de produção.
     *
     * @param  array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        $rules = self::commonRules($data);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
