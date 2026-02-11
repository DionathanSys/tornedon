<?php

namespace App\Services\ProductStock\Validators;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductStockValidator
{
    /**
     * Valida dados para criação de estoque de produto.
     *
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateCreate(array $data): array
    {
        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message' => 'Iniciando validação de dados para criação de estoque de produto',
            'data'    => $data,
        ]);

        $rules = [
            'product_id'            => [
                'required',
                'integer',
                'exists:products,id',
                Rule::unique('product_stocks', 'product_id'),
            ],
            'quantity_available'    => 'nullable|numeric|min:0',
            'quantity_reserved'     => 'nullable|numeric|min:0',
            'quantity_minimum'      => 'nullable|numeric|min:0',
            'quantity_maximum'      => 'nullable|numeric|min:0',
            'average_cost'          => 'nullable|numeric|min:0',
            'last_cost'             => 'nullable|numeric|min:0',
            'last_sale_price'       => 'nullable|numeric|min:0',
            'last_movement_date'    => 'nullable|date',
            'last_movement_type'    => 'nullable|string|max:50',
            'is_active'             => 'nullable|boolean',
            'allow_negative'        => 'nullable|boolean',
            'additional_info'       => 'nullable|array',
            'company_id'            => 'required|integer|exists:companies,id',
        ];

        $messages = [
            'product_id.required'           => 'É obrigatório informar o produto',
            'product_id.exists'             => 'O produto informado não existe',
            'product_id.integer'            => 'O ID do produto deve ser um número inteiro',
            'product_id.unique'             => 'Já existe um registro de estoque para este produto',
            'quantity_available.numeric'    => 'A quantidade disponível deve ser um número',
            'quantity_available.min'        => 'A quantidade disponível não pode ser negativa',
            'quantity_reserved.numeric'     => 'A quantidade reservada deve ser um número',
            'quantity_reserved.min'         => 'A quantidade reservada não pode ser negativa',
            'quantity_minimum.numeric'      => 'A quantidade mínima deve ser um número',
            'quantity_minimum.min'          => 'A quantidade mínima não pode ser negativa',
            'quantity_maximum.numeric'      => 'A quantidade máxima deve ser um número',
            'quantity_maximum.min'          => 'A quantidade máxima não pode ser negativa',
            'average_cost.numeric'          => 'O custo médio deve ser um número',
            'average_cost.min'              => 'O custo médio não pode ser negativo',
            'last_cost.numeric'             => 'O último custo deve ser um número',
            'last_cost.min'                 => 'O último custo não pode ser negativo',
            'last_sale_price.numeric'       => 'O último preço de venda deve ser um número',
            'last_sale_price.min'           => 'O último preço de venda não pode ser negativo',
            'last_movement_date.date'       => 'A data do último movimento deve ser uma data válida',
            'last_movement_type.max'        => 'O tipo do último movimento não pode ter mais de 50 caracteres',
            'is_active.boolean'             => 'O campo ativo deve ser verdadeiro ou falso',
            'allow_negative.boolean'        => 'O campo permitir negativo deve ser verdadeiro ou falso',
            'additional_info.array'         => 'As informações adicionais devem ser uma lista',
            'company_id.required'           => 'É obrigatório informar a empresa',
            'company_id.exists'             => 'A empresa informada não existe',
            'company_id.integer'            => 'O ID da empresa deve ser um número inteiro',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Valida dados para atualização de estoque de produto.
     *
     * @param array $data Dados a validar
     * @param int|null $productStockId ID do estoque sendo atualizado
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateUpdate(array $data, ?int $productStockId = null): array
    {
        $rules = [
            'product_id'            => [
                'sometimes',
                'required',
                'integer',
                'exists:products,id',
                Rule::unique('product_stocks', 'product_id')
                    ->ignore($productStockId)
                    ->whereNull('deleted_at'),
            ],
            'quantity_available'    => 'nullable|numeric|min:0',
            'quantity_reserved'     => 'nullable|numeric|min:0',
            'quantity_minimum'      => 'nullable|numeric|min:0',
            'quantity_maximum'      => 'nullable|numeric|min:0',
            'average_cost'          => 'nullable|numeric|min:0',
            'last_cost'             => 'nullable|numeric|min:0',
            'last_sale_price'       => 'nullable|numeric|min:0',
            'last_movement_date'    => 'nullable|date',
            'last_movement_type'    => 'nullable|string|max:50',
            'is_active'             => 'nullable|boolean',
            'allow_negative'        => 'nullable|boolean',
            'additional_info'       => 'nullable|array',
        ];

        $messages = [
            'product_id.required'           => 'É obrigatório informar o produto',
            'product_id.exists'             => 'O produto informado não existe',
            'product_id.integer'            => 'O ID do produto deve ser um número inteiro',
            'product_id.unique'             => 'Já existe um registro de estoque para este produto',
            'quantity_available.numeric'    => 'A quantidade disponível deve ser um número',
            'quantity_available.min'        => 'A quantidade disponível não pode ser negativa',
            'quantity_reserved.numeric'     => 'A quantidade reservada deve ser um número',
            'quantity_reserved.min'         => 'A quantidade reservada não pode ser negativa',
            'quantity_minimum.numeric'      => 'A quantidade mínima deve ser um número',
            'quantity_minimum.min'          => 'A quantidade mínima não pode ser negativa',
            'quantity_maximum.numeric'      => 'A quantidade máxima deve ser um número',
            'quantity_maximum.min'          => 'A quantidade máxima não pode ser negativa',
            'average_cost.numeric'          => 'O custo médio deve ser um número',
            'average_cost.min'              => 'O custo médio não pode ser negativo',
            'last_cost.numeric'             => 'O último custo deve ser um número',
            'last_cost.min'                 => 'O último custo não pode ser negativo',
            'last_sale_price.numeric'       => 'O último preço de venda deve ser um número',
            'last_sale_price.min'           => 'O último preço de venda não pode ser negativo',
            'last_movement_date.date'       => 'A data do último movimento deve ser uma data válida',
            'last_movement_type.max'        => 'O tipo do último movimento não pode ter mais de 50 caracteres',
            'is_active.boolean'             => 'O campo ativo deve ser verdadeiro ou falso',
            'allow_negative.boolean'        => 'O campo permitir negativo deve ser verdadeiro ou falso',
            'additional_info.array'         => 'As informações adicionais devem ser uma lista',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
