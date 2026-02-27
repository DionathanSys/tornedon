<?php

namespace App\Services\StockMovement\Validators;

use App\Enum\StockMovement\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockMovementValidator
{
    /**
     * Regras comuns de validação
     */
    private static function rules(): array
    {
        $typeValues = array_map(fn($type) => $type->value, Type::cases());

        return [
            'product_stock_id'  => 'required|integer|exists:product_stocks,id',
            'product_id'        => 'required|integer|exists:products,id',
            'type'              => ['required', 'string', Rule::in($typeValues)],
            'quantity'          => 'required|numeric|min:0.001',
            'unit_price'         => 'nullable|numeric|min:0',
            'total_amount'        => 'nullable|numeric|min:0',
            'reason'            => 'nullable|string|max:500',
            'observations'      => 'nullable|string|max:1000',
            'source_type'    => 'nullable|string|max:50',
            'source_id'      => 'nullable|integer',
            'additional_info'   => 'nullable|array',
        ];
    }

    /**
     * Mensagens comuns de validação
     */
    private static function messages(): array
    {
        return [
            'product_stock_id.required'     => 'É obrigatório informar o estoque do produto.',
            'product_stock_id.exists'       => 'O estoque do produto informado não existe.',
            'product_id.required'           => 'É obrigatório informar o produto.',
            'product_id.exists'             => 'O produto informado não existe.',
            'type.required'                 => 'É obrigatório informar o tipo de movimento.',
            'type.in'                       => 'Tipo de movimento inválido.',
            'quantity.required'             => 'É obrigatório informar a quantidade.',
            'quantity.numeric'              => 'A quantidade deve ser um número.',
            'quantity.min'                  => 'A quantidade deve ser maior que zero.',
            'unit_price.numeric'             => 'O preço unitário deve ser um número.',
            'unit_price.min'                 => 'O preço unitário não pode ser negativo.',
            'total_amount.numeric'            => 'O valor total deve ser um número.',
            'total_amount.min'                => 'O valor total não pode ser negativo.',
            'reason.max'                    => 'O motivo não pode ter mais de 500 caracteres.',
            'observations.max'              => 'As observações não podem ter mais de 1000 caracteres.',
            'source_type.max'               => 'O tipo de referência não pode ter mais de 50 caracteres.',
            'additional_info.array'         => 'As informações adicionais devem ser uma lista.',
        ];
    }

    /**
     * Valida dados para criação de movimentação de estoque.
     *
     * @param array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = self::rules();
        $messages = self::messages();

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Valida dados para atualização de movimentação de estoque.
     *
     * @param array $data
     * @return array Dados validados
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        $typeValues = array_map(fn($type) => $type->value, Type::cases());

        $rules = [
            'type'              => ['sometimes', 'string', Rule::in($typeValues)],
            'quantity'          => 'sometimes|numeric|min:0.001',
            'unit_price'        => 'nullable|numeric|min:0',
            'total_amount'      => 'nullable|numeric|min:0',
            'reason'            => 'nullable|string|max:500',
            'observations'      => 'nullable|string|max:1000',
            'source_type'       => 'nullable|string|max:50',
            'source_id'         => 'nullable|integer',
            'additional_info'   => 'nullable|array',
        ];

        $messages = self::messages();

        return Validator::make($data, $rules, $messages)->validate();
    }
}
