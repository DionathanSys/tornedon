<?php

namespace App\Services\ProductionOrderItem\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ProductionOrderItemValidator
{
    /**
     * Regras comuns de validacao (campos compartilhados entre create e update).
     */
    private static function commonRules(): array
    {
        return [
            'technical_specifications' => 'nullable|array',
            'production_notes' => 'nullable|string|max:1000',
            'qc_notes' => 'nullable|string|max:1000',
            'additional_info' => 'nullable|array',
        ];
    }

    /**
     * Mensagens de validacao compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'production_order_id.required' => 'E obrigatorio informar a ordem de producao.',
            'production_order_id.exists' => 'A ordem de producao informada nao existe.',
            'quote_item_id.exists' => 'O item de orcamento informado nao existe.',
            'product_id.required' => 'E obrigatorio informar o produto.',
            'product_id.exists' => 'O produto informado nao existe.',
            'description.required' => 'E obrigatorio informar a descricao.',
            'description.max' => 'A descricao nao pode ter mais de 500 caracteres.',
            'quantity.required' => 'E obrigatorio informar a quantidade.',
            'quantity.numeric' => 'A quantidade deve ser um numero.',
            'quantity.min' => 'A quantidade deve ser maior que zero.',
            'unit_price.required' => 'E obrigatorio informar o valor unitario.',
            'unit_price.numeric' => 'O valor unitario deve ser um numero.',
            'unit_price.min' => 'O valor unitario nao pode ser negativo.',
            'unit_cost.numeric' => 'O custo unitario deve ser um numero.',
            'unit_cost.min' => 'O custo unitario nao pode ser negativo.',
            'discount_percentage.numeric' => 'O desconto percentual deve ser um numero.',
            'discount_percentage.min' => 'O desconto percentual nao pode ser negativo.',
            'discount_percentage.max' => 'O desconto percentual nao pode ser maior que 100%.',
            'discount_amount.numeric' => 'O valor do desconto deve ser um numero.',
            'discount_amount.min' => 'O valor do desconto nao pode ser negativo.',
            'quantity_produced.numeric' => 'A quantidade produzida deve ser um numero.',
            'quantity_produced.min' => 'A quantidade produzida nao pode ser negativa.',
            'quantity_approved.numeric' => 'A quantidade aprovada deve ser um numero.',
            'quantity_approved.min' => 'A quantidade aprovada nao pode ser negativa.',
            'quantity_rejected.numeric' => 'A quantidade rejeitada deve ser um numero.',
            'quantity_rejected.min' => 'A quantidade rejeitada nao pode ser negativa.',
            'unit_of_measure.required' => 'E obrigatorio informar a unidade de medida.',
            'unit_of_measure.max' => 'A unidade de medida nao pode ter mais de 20 caracteres.',
            'production_notes.max' => 'As notas de producao nao podem ter mais de 1000 caracteres.',
            'qc_notes.max' => 'As notas de QC nao podem ter mais de 1000 caracteres.',
            'actual_production_hours.numeric' => 'As horas de producao devem ser um numero.',
            'actual_production_hours.min' => 'As horas de producao nao podem ser negativas.',
            'sequence.integer' => 'A sequencia deve ser um numero inteiro.',
            'sequence.min' => 'A sequencia deve ser maior que zero.',
            'additional_info.array' => 'As informacoes adicionais devem ser uma lista.',
        ];
    }

    /**
     * Valida dados para criacao de item de ordem de producao.
     *
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'production_order_id' => 'required|integer|exists:production_orders,id',
            'quote_item_id' => 'nullable|integer|exists:quote_items,id',
            'product_id' => 'required|integer|exists:products,id',
            'description' => 'required|string|max:500',
            'quantity' => 'required|numeric|min:0.001',
            'unit_price' => 'required|numeric|min:0',
            'unit_cost' => 'nullable|numeric|min:0',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'unit_of_measure' => 'required|string|max:20',
            'sequence' => 'nullable|integer|min:1',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualizacao de item de ordem de producao.
     *
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'description' => 'sometimes|string|max:500',
            'quantity' => 'sometimes|numeric|min:0.001',
            'unit_price' => 'sometimes|numeric|min:0',
            'unit_cost' => 'sometimes|nullable|numeric|min:0',
            'discount_percentage' => 'sometimes|nullable|numeric|min:0|max:100',
            'discount_amount' => 'sometimes|nullable|numeric|min:0',
            'quantity_produced' => 'sometimes|numeric|min:0',
            'quantity_approved' => 'sometimes|numeric|min:0',
            'quantity_rejected' => 'sometimes|numeric|min:0',
            'unit_of_measure' => 'sometimes|string|max:20',
            'actual_production_hours' => 'nullable|numeric|min:0',
            'sequence' => 'sometimes|integer|min:1',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
