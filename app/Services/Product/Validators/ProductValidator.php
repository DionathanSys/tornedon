<?php

namespace App\Services\Product\Validators;

use App\Enum\Product\Unit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductValidator
{
    /**
     * Valida dados para criação de produto.
     *
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateCreate(array $data): array
    {
        $unitValues = array_map(fn($unit) => $unit->value, Unit::cases());

        Log::debug(__METHOD__ . '@' . __LINE__, [
            'message' => 'Iniciando validação de dados para criação de produto',
            'data'    => $data,
            'units'   => $unitValues,
        ]);

        $rules = [
            'name'                      => 'required|string|max:255',
            'description'               => 'nullable|string|max:1000',
            'category_id'               => 'nullable|integer|exists:categories,id',
            'is_active'                 => 'nullable|boolean',
            'is_custom_manufacturing'   => 'nullable|boolean',
            'unit'                      => ['required', Rule::in($unitValues)],
            'alternative_units'         => 'nullable|array',
            'alternative_units.*'       => ['string', Rule::in($unitValues)],
            'profit_margin'             => 'nullable|numeric|min:0|max:100',
            'min_sale_price'            => 'nullable|numeric|min:0',
            'company_id'                => 'required|integer|exists:companies,id',
            'product_code'              => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'product_code')->where('company_id', $data['company_id'] ?? null),
            ],
        ];

        $messages = [
            'name.required'                     => 'É obrigatório informar o nome do produto',
            'name.max'                          => 'O nome do produto não pode ter mais de 255 caracteres',
            'description.max'                   => 'A descrição não pode ter mais de 1000 caracteres',
            'category_id.exists'                => 'A categoria informada não existe',
            'category_id.integer'               => 'O ID da categoria deve ser um número inteiro',
            'is_active.boolean'                 => 'O campo ativo deve ser verdadeiro ou falso',
            'is_custom_manufacturing.boolean'   => 'O campo fabricação customizada deve ser verdadeiro ou falso',
            'unit.required'                     => 'É obrigatório informar a unidade de medida',
            'unit.in'                           => 'A unidade de medida informada é inválida',
            'alternative_units.array'           => 'As unidades alternativas devem ser uma lista',
            'alternative_units.*.in'            => 'Uma das unidades alternativas informadas é inválida',
            'profit_margin.numeric'             => 'A margem de lucro deve ser um número',
            'profit_margin.min'                 => 'A margem de lucro não pode ser negativa',
            'profit_margin.max'                 => 'A margem de lucro não pode ser maior que 100%',
            'min_sale_price.numeric'            => 'O preço mínimo de venda deve ser um número',
            'min_sale_price.min'                => 'O preço mínimo de venda não pode ser negativo',
            'company_id.required'               => 'É obrigatório informar a empresa',
            'company_id.exists'                 => 'A empresa informada não existe',
            'company_id.integer'                => 'O ID da empresa deve ser um número inteiro',
            'product_code.unique'               => 'Já existe um produto com este código',
            'product_code.max'                  => 'O código do produto não pode ter mais de 50 caracteres',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Valida dados para atualização de produto.
     *
     * @param array $data Dados a validar
     * @param int|null $productId ID do produto sendo atualizado (para ignorar na validação unique)
     * @return array Retorna dados validados
     * @throws ValidationException Se a validação falhar
     */
    public static function validateUpdate(array $data, ?int $productId = null, ?int $companyId = null): array
    {
        $unitValues = array_map(fn($unit) => $unit->value, Unit::cases());

        $rules = [
            'name'                      => 'sometimes|required|string|max:255',
            'description'               => 'nullable|string|max:1000',
            'category_id'               => 'nullable|integer|exists:categories,id',
            'is_active'                 => 'nullable|boolean',
            'is_custom_manufacturing'   => 'nullable|boolean',
            'unit'                      => ['sometimes', 'required', Rule::in($unitValues)],
            'alternative_units'         => 'nullable|array',
            'alternative_units.*'       => ['string', Rule::in($unitValues)],
            'profit_margin'             => 'nullable|numeric|min:0|max:100',
            'min_sale_price'            => 'nullable|numeric|min:0',
        ];

        // Adiciona validação de product_code apenas se o campo estiver presente nos dados
        if (isset($data['product_code']) && $companyId) {
            $rules['product_code'] = [
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'product_code')
                    ->where('company_id', $companyId)
                    ->ignore($productId),
            ];
        }

        $messages = [
            'name.required'                     => 'É obrigatório informar o nome do produto',
            'name.max'                          => 'O nome do produto não pode ter mais de 255 caracteres',
            'description.max'                   => 'A descrição não pode ter mais de 1000 caracteres',
            'category_id.exists'                => 'A categoria informada não existe',
            'category_id.integer'               => 'O ID da categoria deve ser um número inteiro',
            'is_active.boolean'                 => 'O campo ativo deve ser verdadeiro ou falso',
            'is_custom_manufacturing.boolean'   => 'O campo fabricação customizada deve ser verdadeiro ou falso',
            'unit.required'                     => 'É obrigatório informar a unidade de medida',
            'unit.in'                           => 'A unidade de medida informada é inválida',
            'alternative_units.array'           => 'As unidades alternativas devem ser uma lista',
            'alternative_units.*.in'            => 'Uma das unidades alternativas informadas é inválida',
            'profit_margin.numeric'             => 'A margem de lucro deve ser um número',
            'profit_margin.min'                 => 'A margem de lucro não pode ser negativa',
            'profit_margin.max'                 => 'A margem de lucro não pode ser maior que 100%',
            'min_sale_price.numeric'            => 'O preço mínimo de venda deve ser um número',
            'min_sale_price.min'                => 'O preço mínimo de venda não pode ser negativo',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
