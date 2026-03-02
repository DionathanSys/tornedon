<?php

namespace App\Services\ServiceOrderItem\Validators;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServiceOrderItemValidator
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
            'service_order_id.required'     => 'É obrigatório informar a ordem de serviço',
            'service_order_id.exists'       => 'A ordem de serviço informada não existe',
            'service_id.required'           => 'É obrigatório informar o serviço',
            'service_id.exists'             => 'O serviço informado não existe',
            'quantity.required'             => 'É obrigatório informar a quantidade',
            'quantity.numeric'              => 'A quantidade deve ser um número',
            'quantity.min'                  => 'A quantidade deve ser maior que zero',
            'unit_price.required'           => 'É obrigatório informar o preço unitário',
            'unit_price.numeric'            => 'O preço unitário deve ser um número',
            'unit_price.min'                => 'O preço unitário não pode ser negativo',
            'unit_cost.numeric'             => 'O custo unitário deve ser um número',
            'unit_cost.min'                 => 'O custo unitário não pode ser negativo',
            'discount_percentage.numeric'   => 'O desconto percentual deve ser um número',
            'discount_percentage.min'       => 'O desconto percentual não pode ser negativo',
            'discount_percentage.max'       => 'O desconto percentual não pode ser maior que 100%',
            'discount_amount.numeric'       => 'O valor do desconto deve ser um número',
            'discount_amount.min'           => 'O valor do desconto não pode ser negativo',
            'observations.max'              => 'As observações não podem ter mais de 1000 caracteres',
            'additional_info.array'         => 'As informações adicionais devem ser uma lista',
        ];
    }

    /**
     * Valida dados para criação de item de ordem de serviço.
     *
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateCreate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'service_order_id'  => 'required|integer|exists:service_orders,id',
            'service_id'        => 'required|integer|exists:services,id',
            'quantity'          => 'required|numeric|min:0.001',
            'unit_price'        => 'required|numeric|min:0',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualização de item de ordem de serviço.
     *
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateUpdate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'service_order_id'  => 'sometimes|integer|exists:service_orders,id',
            'service_id'        => 'sometimes|integer|exists:services,id',
            'quantity'          => 'sometimes|numeric|min:0.001',
            'unit_price'        => 'sometimes|numeric|min:0',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
