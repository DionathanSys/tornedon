<?php

namespace App\Services\RequisitionItem\Validators;

use App\Enum\Product\Unit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RequisitionItemValidator
{
    /**
     * Regras comuns de validação (campos compartilhados entre create e update).
     */
    private static function commonRules(): array
    {
        return [
            'unit_cost'             => 'nullable|numeric|min:0',
            'discount_percentage'   => 'nullable|numeric|min:0|max:100',
            'discount_amount'       => 'nullable|numeric|min:0',
            'observations'          => 'nullable|string|max:1000',
            'additional_info'       => 'nullable|array',
        ];
    }

    /**
     * Mensagens de validação compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'requisition_id.required'       => 'É obrigatório informar a requisição.',
            'requisition_id.exists'         => 'A requisição informada não existe.',
            'product_id.required'           => 'É obrigatório informar o produto.',
            'product_id.exists'             => 'O produto informado não existe.',
            'unit_of_measure.string'        => 'A unidade de medida deve ser uma string.',
            'unit_of_measure.in'            => 'A unidade de medida não é válida.',
            'quantity.required'             => 'É obrigatório informar a quantidade.',
            'quantity.numeric'              => 'A quantidade deve ser um número.',
            'quantity.min'                  => 'A quantidade deve ser maior que zero.',
            'unit_price.required'           => 'É obrigatório informar o preço unitário.',
            'unit_price.numeric'            => 'O preço unitário deve ser um número.',
            'unit_price.min'                => 'O preço unitário não pode ser negativo.',
            'unit_cost.numeric'             => 'O custo unitário deve ser um número.',
            'unit_cost.min'                 => 'O custo unitário não pode ser negativo.',
            'discount_percentage.numeric'   => 'O desconto percentual deve ser um número.',
            'discount_percentage.min'       => 'O desconto percentual não pode ser negativo.',
            'discount_percentage.max'       => 'O desconto percentual não pode ser maior que 100%.',
            'discount_amount.numeric'       => 'O valor do desconto deve ser um número.',
            'discount_amount.min'           => 'O valor do desconto não pode ser negativo.',
            'observations.max'              => 'As observações não podem ter mais de 1000 caracteres.',
            'additional_info.array'         => 'As informações adicionais devem ser uma lista.',
        ];
    }

    /**
     * Valida dados para criação de item de requisição.
     *
     * @param array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $unitValues = array_map(fn($unit) => $unit->value, Unit::cases());

        $rules = array_merge(self::commonRules(), [
            'requisition_id'    => 'required|integer|exists:requisitions,id',
            'product_id'        => 'required|integer|exists:products,id',
            'unit_of_measure'   => ['string', 'max:20', Rule::in($unitValues)],
            'quantity'          => 'required|numeric|min:0.001',
            'unit_price'        => 'required|numeric|min:0',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualização de item de requisição.
     *
     * @param array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        $unitValues = array_map(fn($unit) => $unit->value, Unit::cases());

        $rules = array_merge(self::commonRules(), [
            'product_id'        => 'sometimes|integer|exists:products,id',
            'unit_of_measure'   => ['nullable', 'string', 'max:20', Rule::in($unitValues)],
            'quantity'          => 'sometimes|numeric|min:0.001',
            'unit_price'        => 'sometimes|numeric|min:0',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
