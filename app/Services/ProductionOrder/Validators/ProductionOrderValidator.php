<?php

namespace App\Services\ProductionOrder\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderValidator
{
    /**
     * Valida dados para criação de ordem de produção.
     *
     * @param  array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $validator = Validator::make($data, [
            'company_id'        => 'required|integer|exists:companies,id',
            'partner_id'        => 'required|integer|exists:partners,id',
            'quote_id'          => 'nullable|integer|exists:quotes,id',
            'priority'          => 'required|in:low,normal,high,urgent',
            'destination_type'  => 'required|in:stock,direct_delivery',
            'observations'      => 'nullable|string',
            'assigned_operator' => 'nullable|integer|exists:users,id',
            'assigned_machine'  => 'nullable|string',
        ], [
            'company_id.required'       => 'A empresa é obrigatória.',
            'company_id.exists'         => 'Empresa não encontrada.',
            'partner_id.required'       => 'O cliente é obrigatório.',
            'partner_id.exists'         => 'Cliente não encontrado.',
            'quote_id.exists'           => 'Orçamento não encontrado.',
            'priority.required'         => 'A prioridade é obrigatória.',
            'priority.in'               => 'Prioridade inválida.',
            'destination_type.required' => 'O tipo de destino é obrigatório.',
            'destination_type.in'       => 'Tipo de destino inválido.',
            'assigned_operator.exists'  => 'Operador designado não encontrado.',
        ]);

        return $validator->validate();
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
        $validator = Validator::make($data, [
            'company_id'        => 'sometimes|required|integer|exists:companies,id',
            'partner_id'        => 'sometimes|required|integer|exists:partners,id',
            'quote_id'          => 'nullable|integer|exists:quotes,id',
            'priority'          => 'sometimes|required|in:low,normal,high,urgent',
            'destination_type'  => 'sometimes|required|in:stock,direct_delivery',
            'observations'      => 'nullable|string',
            'assigned_operator' => 'nullable|integer|exists:users,id',
            'assigned_machine'  => 'nullable|string',
        ], [
            'company_id.required'       => 'A empresa é obrigatória.',
            'company_id.exists'         => 'Empresa não encontrada.',
            'partner_id.required'       => 'O cliente é obrigatório.',
            'partner_id.exists'         => 'Cliente não encontrado.',
            'quote_id.exists'           => 'Orçamento não encontrado.',
            'priority.required'         => 'A prioridade é obrigatória.',
            'priority.in'               => 'Prioridade inválida.',
            'destination_type.required' => 'O tipo de destino é obrigatório.',
            'destination_type.in'       => 'Tipo de destino inválido.',
            'assigned_operator.exists'  => 'Operador designado não encontrado.',
        ]);

        return $validator->validate();
    }
}
