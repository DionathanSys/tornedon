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
        return [
            'product_stock_id'  => 'required|integer|exists:product_stocks,id',
            'product_id'        => 'required|integer|exists:products,id',
            'company_id'        => 'required|integer|exists:companies,id',
            'type'              => ['required', 'string', Rule::enum(Type::class)],
            'quantity'          => 'required|numeric|min:0.001',
            'unit_price'        => 'required|numeric|min:0',
            'reason'            => 'nullable|string|max:500',
            'observations'      => 'nullable|string|max:1000',
            'source_type'       => 'required|string|max:50',
            'source_id'         => 'required|integer',
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
            'company_id.required'           => 'É obrigatório informar a empresa.',
            'company_id.exists'             => 'A empresa informada não existe.',
            'type.required'                 => 'É obrigatório informar o tipo de movimento.',
            'type.in'                       => 'Tipo de movimento inválido.',
            'quantity.required'             => 'É obrigatório informar a quantidade.',
            'quantity.numeric'              => 'A quantidade deve ser um número.',
            'quantity.min'                  => 'A quantidade deve ser maior que zero.',
            'unit_price.numeric'            => 'O preço unitário deve ser um número.',
            'unit_price.min'                => 'O preço unitário não pode ser negativo.',
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
        $rules = self::rules();
        $messages = self::messages();

        return Validator::make($data, $rules, $messages)->validate();
    }
}
