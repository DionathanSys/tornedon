<?php

namespace App\Services\ProductionOrderItem\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemValidator
{
    /**
     * Valida dados para criação de item de ordem de produção.
     *
     * @param array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = [
            'production_order_id'           => 'required|integer|exists:production_orders,id',
            'quote_item_id'                 => 'nullable|integer|exists:quote_items,id',
            'product_id'                    => 'required|integer|exists:products,id',
            'description'                   => 'required|string|max:500',
            'quantity'                      => 'required|numeric|min:0.001',
            'unit_of_measure'               => 'required|string|max:20',
            'technical_specifications'      => 'nullable|array',
            'production_notes'              => 'nullable|string|max:1000',
            'qc_notes'                      => 'nullable|string|max:1000',
            'sequence'                      => 'nullable|integer|min:1',
            'additional_info'               => 'nullable|array',
        ];

        $messages = [
            'production_order_id.required'  => 'É obrigatório informar a ordem de produção.',
            'production_order_id.exists'    => 'A ordem de produção informada não existe.',
            'quote_item_id.exists'          => 'O item de orçamento informado não existe.',
            'product_id.required'           => 'É obrigatório informar o produto.',
            'product_id.exists'             => 'O produto informado não existe.',
            'description.required'          => 'É obrigatório informar a descrição.',
            'description.max'               => 'A descrição não pode ter mais de 500 caracteres.',
            'quantity.required'             => 'É obrigatório informar a quantidade.',
            'quantity.numeric'              => 'A quantidade deve ser um número.',
            'quantity.min'                  => 'A quantidade deve ser maior que zero.',
            'unit_of_measure.required'      => 'É obrigatório informar a unidade de medida.',
            'unit_of_measure.max'           => 'A unidade de medida não pode ter mais de 20 caracteres.',
            'production_notes.max'          => 'As notas de produção não podem ter mais de 1000 caracteres.',
            'qc_notes.max'                  => 'As notas de QC não podem ter mais de 1000 caracteres.',
            'sequence.integer'              => 'A sequência deve ser um número inteiro.',
            'sequence.min'                  => 'A sequência deve ser maior que zero.',
            'additional_info.array'         => 'As informações adicionais devem ser uma lista.',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Valida dados para atualização de item de ordem de produção.
     *
     * @param array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        $rules = [
            'description'                   => 'sometimes|string|max:500',
            'quantity'                      => 'sometimes|numeric|min:0.001',
            'quantity_produced'             => 'sometimes|numeric|min:0',
            'quantity_approved'             => 'sometimes|numeric|min:0',
            'quantity_rejected'             => 'sometimes|numeric|min:0',
            'unit_of_measure'               => 'sometimes|string|max:20',
            'technical_specifications'      => 'nullable|array',
            'production_notes'              => 'nullable|string|max:1000',
            'qc_notes'                      => 'nullable|string|max:1000',
            'actual_production_hours'       => 'nullable|numeric|min:0',
            'sequence'                      => 'sometimes|integer|min:1',
            'additional_info'               => 'nullable|array',
        ];

        $messages = [
            'description.max'               => 'A descrição não pode ter mais de 500 caracteres.',
            'quantity.numeric'              => 'A quantidade deve ser um número.',
            'quantity.min'                  => 'A quantidade deve ser maior que zero.',
            'quantity_produced.numeric'     => 'A quantidade produzida deve ser um número.',
            'quantity_produced.min'         => 'A quantidade produzida não pode ser negativa.',
            'quantity_approved.numeric'     => 'A quantidade aprovada deve ser um número.',
            'quantity_approved.min'         => 'A quantidade aprovada não pode ser negativa.',
            'quantity_rejected.numeric'     => 'A quantidade rejeitada deve ser um número.',
            'quantity_rejected.min'         => 'A quantidade rejeitada não pode ser negativa.',
            'unit_of_measure.max'           => 'A unidade de medida não pode ter mais de 20 caracteres.',
            'production_notes.max'          => 'As notas de produção não podem ter mais de 1000 caracteres.',
            'qc_notes.max'                  => 'As notas de QC não podem ter mais de 1000 caracteres.',
            'actual_production_hours.numeric' => 'As horas de produção devem ser um número.',
            'actual_production_hours.min'   => 'As horas de produção não podem ser negativas.',
            'sequence.integer'              => 'A sequência deve ser um número inteiro.',
            'sequence.min'                  => 'A sequência deve ser maior que zero.',
            'additional_info.array'         => 'As informações adicionais devem ser uma lista.',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
