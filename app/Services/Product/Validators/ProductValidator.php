<?php

namespace App\Services\Product\Validators;

use App\Enum\Product\ItemType;
use App\Enum\Product\OriginSalePrice;
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

        $originSalePriceValues = array_map(fn($o) => $o->value, OriginSalePrice::cases());
        $itemTypeValues = array_map(fn($t) => $t->value, ItemType::cases());

        $rules = [
            'name'                      => 'required|string|max:255',
            'description'               => 'nullable|string|max:1000',
            'category_id'               => 'nullable|integer|exists:categories,id',
            'is_active'                 => 'nullable|boolean',
            'is_custom_manufacturing'   => 'nullable|boolean',
            'has_stock_control'         => 'nullable|boolean',
            'unit'                      => ['required', Rule::in($unitValues)],
            'alternative_units'         => 'nullable|array',
            'alternative_units.*'       => ['string', Rule::in($unitValues)],
            'profit_margin'             => 'nullable|numeric|min:0|max:100',
            'min_sale_price'            => 'nullable|numeric|min:0',
            'origin_sale_price'         => ['nullable', Rule::in($originSalePriceValues)],
            'sale_price_value'          => 'nullable|numeric|min:0',
            'external_reference_codes'  => 'nullable|array',
            'item_type'                 => ['nullable', Rule::in($itemTypeValues)],
            'manufacturer_code'         => 'nullable|string|max:100',
            'gross_weight'              => 'nullable|numeric|min:0',
            'net_weight'                => 'nullable|numeric|min:0',
            'barcode'                   => 'nullable|string|max:60',
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
            'has_stock_control.boolean'         => 'O campo controle de estoque deve ser verdadeiro ou falso',
            'unit.required'                     => 'É obrigatório informar a unidade de medida',
            'unit.in'                           => 'A unidade de medida informada é inválida',
            'alternative_units.array'           => 'As unidades alternativas devem ser uma lista',
            'alternative_units.*.in'            => 'Uma das unidades alternativas informadas é inválida',
            'profit_margin.numeric'             => 'A margem de lucro deve ser um número',
            'profit_margin.min'                 => 'A margem de lucro não pode ser negativa',
            'profit_margin.max'                 => 'A margem de lucro não pode ser maior que 100%',
            'min_sale_price.numeric'            => 'O preço mínimo de venda deve ser um número',
            'min_sale_price.min'                => 'O preço mínimo de venda não pode ser negativo',
            'origin_sale_price.in'              => 'A origem do preço de venda informada é inválida',
            'sale_price_value.numeric'          => 'O valor de venda deve ser um número',
            'sale_price_value.min'              => 'O valor de venda não pode ser negativo',
            'external_reference_codes.array'    => 'Os códigos de referência externa devem ser uma lista',
            'item_type.in'                      => 'O tipo de item informado é inválido',
            'manufacturer_code.max'             => 'O código de fábrica não pode ter mais de 100 caracteres',
            'gross_weight.numeric'              => 'O peso bruto deve ser um número',
            'gross_weight.min'                  => 'O peso bruto não pode ser negativo',
            'net_weight.numeric'                => 'O peso líquido deve ser um número',
            'net_weight.min'                    => 'O peso líquido não pode ser negativo',
            'barcode.max'                       => 'O código de barras não pode ter mais de 60 caracteres',
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

        $originSalePriceValues = array_map(fn($o) => $o->value, OriginSalePrice::cases());
        $itemTypeValues = array_map(fn($t) => $t->value, ItemType::cases());

        $rules = [
            'name'                      => 'sometimes|required|string|max:255',
            'description'               => 'nullable|string|max:1000',
            'category_id'               => 'nullable|integer|exists:categories,id',
            'is_active'                 => 'nullable|boolean',
            'is_custom_manufacturing'   => 'nullable|boolean',
            'has_stock_control'         => 'nullable|boolean',
            'unit'                      => ['sometimes', 'required', Rule::in($unitValues)],
            'alternative_units'         => 'nullable|array',
            'alternative_units.*'       => ['string', Rule::in($unitValues)],
            'profit_margin'             => 'nullable|numeric|min:0|max:100',
            'min_sale_price'            => 'nullable|numeric|min:0',
            'origin_sale_price'         => ['nullable', Rule::in($originSalePriceValues)],
            'sale_price_value'          => 'nullable|numeric|min:0',
            'external_reference_codes'  => 'nullable|array',
            'item_type'                 => ['nullable', Rule::in($itemTypeValues)],
            'manufacturer_code'         => 'nullable|string|max:100',
            'gross_weight'              => 'nullable|numeric|min:0',
            'net_weight'                => 'nullable|numeric|min:0',
            'barcode'                   => 'nullable|string|max:60',
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
            'has_stock_control.boolean'         => 'O campo controle de estoque deve ser verdadeiro ou falso',
            'unit.required'                     => 'É obrigatório informar a unidade de medida',
            'unit.in'                           => 'A unidade de medida informada é inválida',
            'alternative_units.array'           => 'As unidades alternativas devem ser uma lista',
            'alternative_units.*.in'            => 'Uma das unidades alternativas informadas é inválida',
            'profit_margin.numeric'             => 'A margem de lucro deve ser um número',
            'profit_margin.min'                 => 'A margem de lucro não pode ser negativa',
            'profit_margin.max'                 => 'A margem de lucro não pode ser maior que 100%',
            'min_sale_price.numeric'            => 'O preço mínimo de venda deve ser um número',
            'min_sale_price.min'                => 'O preço mínimo de venda não pode ser negativo',
            'origin_sale_price.in'              => 'A origem do preço de venda informada é inválida',
            'sale_price_value.numeric'          => 'O valor de venda deve ser um número',
            'sale_price_value.min'              => 'O valor de venda não pode ser negativo',
            'external_reference_codes.array'    => 'Os códigos de referência externa devem ser uma lista',
            'item_type.in'                      => 'O tipo de item informado é inválido',
            'manufacturer_code.max'             => 'O código de fábrica não pode ter mais de 100 caracteres',
            'gross_weight.numeric'              => 'O peso bruto deve ser um número',
            'gross_weight.min'                  => 'O peso bruto não pode ser negativo',
            'net_weight.numeric'                => 'O peso líquido deve ser um número',
            'net_weight.min'                    => 'O peso líquido não pode ser negativo',
            'barcode.max'                       => 'O código de barras não pode ter mais de 60 caracteres',
        ];

        return Validator::make($data, $rules, $messages)->validate();
    }
}
