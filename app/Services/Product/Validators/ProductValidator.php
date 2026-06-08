<?php

namespace App\Services\Product\Validators;

use App\Enum\Product\ItemType;
use App\Enum\Product\OriginSalePrice;
use App\Enum\Product\Unit;
use App\Models\Product;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductValidator
{
    /**
     * Regras comuns de validacao (campos compartilhados entre create e update).
     */
    private static function commonRules(): array
    {
        $unitValues = array_map(fn($unit) => $unit->value, Unit::cases());
        $originSalePriceValues = array_map(fn($o) => $o->value, OriginSalePrice::cases());
        $itemTypeValues = array_map(fn($t) => $t->value, ItemType::cases());

        return [
            'description'                               => 'nullable|string|max:1000',
            'category_id'                               => 'nullable|integer|exists:categories,id',
            'is_active'                                 => 'nullable|boolean',
            'is_custom_manufacturing'                   => 'nullable|boolean',
            'has_stock_control'                         => 'nullable|boolean',
            'is_invoiceable'                           => 'nullable|boolean',
            'alternative_unit_conversions'              => 'nullable|array',
            'alternative_unit_conversions.*.unit'       => ['required_with:alternative_unit_conversions', 'string', Rule::in($unitValues), 'distinct'],
            'alternative_unit_conversions.*.conversion_factor' => 'required_with:alternative_unit_conversions|numeric|gt:0',
            'profit_margin'                             => 'nullable|numeric|min:0|max:100',
            'min_sale_price'                            => 'nullable|numeric|min:0',
            'origin_sale_price'                         => ['nullable', Rule::in($originSalePriceValues)],
            'sale_price_value'                          => 'nullable|numeric|min:0',
            'external_reference_codes'                  => 'nullable|array',
            'item_type'                                 => ['nullable', Rule::in($itemTypeValues)],
            'manufacturer_code'                         => 'nullable|string|max:100',
            'gross_weight'                              => 'nullable|numeric|min:0',
            'net_weight'                                => 'nullable|numeric|min:0',
            'barcode'                                   => 'nullable|string|max:60',
        ];
    }

    /**
     * Mensagens de validacao compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'name.required'                                     => 'E obrigatorio informar o nome do produto',
            'name.max'                                          => 'O nome do produto nao pode ter mais de 255 caracteres',
            'description.max'                                   => 'A descricao nao pode ter mais de 1000 caracteres',
            'category_id.exists'                                => 'A categoria informada nao existe',
            'category_id.integer'                               => 'O ID da categoria deve ser um numero inteiro',
            'is_active.boolean'                                 => 'O campo ativo deve ser verdadeiro ou falso',
            'is_custom_manufacturing.boolean'                   => 'O campo fabricacao customizada deve ser verdadeiro ou falso',
            'has_stock_control.boolean'                         => 'O campo controle de estoque deve ser verdadeiro ou falso',
            'is_invoiceable.boolean'                            => 'O campo faturável deve ser verdadeiro ou falso',
            'unit.required'                                     => 'E obrigatorio informar a unidade de medida',
            'unit.in'                                           => 'A unidade de medida informada e invalida',
            'alternative_unit_conversions.array'                => 'As conversoes de unidade alternativa devem ser uma lista',
            'alternative_unit_conversions.*.unit.required_with' => 'Informe a unidade da conversao alternativa',
            'alternative_unit_conversions.*.unit.in'            => 'A unidade alternativa informada e invalida',
            'alternative_unit_conversions.*.unit.distinct'      => 'Nao repita unidades alternativas na conversao',
            'alternative_unit_conversions.*.conversion_factor.required_with' => 'Informe o fator de conversao da unidade alternativa',
            'alternative_unit_conversions.*.conversion_factor.numeric' => 'O fator de conversao deve ser numerico',
            'alternative_unit_conversions.*.conversion_factor.gt' => 'O fator de conversao deve ser maior que zero',
            'profit_margin.numeric'                             => 'A margem de lucro deve ser um numero',
            'profit_margin.min'                                 => 'A margem de lucro nao pode ser negativa',
            'profit_margin.max'                                 => 'A margem de lucro nao pode ser maior que 100%',
            'min_sale_price.numeric'                            => 'O preco minimo de venda deve ser um numero',
            'min_sale_price.min'                                => 'O preco minimo de venda nao pode ser negativo',
            'origin_sale_price.in'                              => 'A origem do preco de venda informada e invalida',
            'sale_price_value.numeric'                          => 'O valor de venda deve ser um numero',
            'sale_price_value.min'                              => 'O valor de venda nao pode ser negativo',
            'external_reference_codes.array'                    => 'Os codigos de referencia externa devem ser uma lista',
            'item_type.in'                                      => 'O tipo de item informado e invalido',
            'manufacturer_code.max'                             => 'O codigo de fabrica nao pode ter mais de 100 caracteres',
            'gross_weight.numeric'                              => 'O peso bruto deve ser um numero',
            'gross_weight.min'                                  => 'O peso bruto nao pode ser negativo',
            'net_weight.numeric'                                => 'O peso liquido deve ser um numero',
            'net_weight.min'                                    => 'O peso liquido nao pode ser negativo',
            'barcode.max'                                       => 'O codigo de barras nao pode ter mais de 60 caracteres',
            'company_id.required'                               => 'E obrigatorio informar a empresa',
            'company_id.exists'                                 => 'A empresa informada nao existe',
            'company_id.integer'                                => 'O ID da empresa deve ser um numero inteiro',
            'product_code.unique'                               => 'Ja existe um produto com este codigo',
            'product_code.max'                                  => 'O codigo do produto nao pode ter mais de 50 caracteres',
        ];
    }

    /**
     * Valida dados para criacao de produto.
     *
     * @param array $data Dados a validar
     * @return array Retorna dados validados
     * @throws ValidationException Se a validacao falhar
     */
    public static function validateCreate(array $data): array
    {
        $unitValues = array_map(fn($unit) => $unit->value, Unit::cases());

        $rules = array_merge(self::commonRules(), [
            'name'          => 'required|string|max:255',
            'unit'          => ['required', Rule::in($unitValues)],
            'company_id'    => 'required|integer|exists:companies,id',
            'product_code'  => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('products', 'product_code')->where('company_id', $data['company_id'] ?? null),
            ],
        ]);

        return self::validateWithBaseUnit($data, $rules);
    }

    /**
     * Valida dados para atualizacao de produto.
     *
     * @param array $data Dados a validar
     * @param int|null $productId ID do produto sendo atualizado (para ignorar na validacao unique)
     * @return array Retorna dados validados
     * @throws ValidationException Se a validacao falhar
     */
    public static function validateUpdate(array $data, ?int $productId = null, ?int $companyId = null): array
    {
        $unitValues = array_map(fn($unit) => $unit->value, Unit::cases());

        $rules = array_merge(self::commonRules(), [
            'name' => 'sometimes|required|string|max:255',
            'unit' => ['sometimes', 'required', Rule::in($unitValues)],
        ]);

        // Adiciona validacao de product_code apenas se o campo estiver presente nos dados
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

        return self::validateWithBaseUnit($data, $rules, $productId);
    }

    private static function validateWithBaseUnit(array $data, array $rules, ?int $productId = null): array
    {
        $validator = Validator::make($data, $rules, self::messages());

        $validator->after(function ($validator) use ($data, $productId): void {
            if (empty($data['alternative_unit_conversions']) || !is_array($data['alternative_unit_conversions'])) {
                return;
            }

            $baseUnit = $data['unit'] ?? null;

            if ($baseUnit === null && $productId !== null) {
                $storedUnit = Product::query()
                    ->whereKey($productId)
                    ->value('unit');

                $baseUnit = $storedUnit instanceof Unit
                    ? $storedUnit->value
                    : (string) $storedUnit;
            }

            if (!$baseUnit) {
                return;
            }

            foreach ($data['alternative_unit_conversions'] as $index => $conversion) {
                $alternativeUnit = $conversion['unit'] ?? null;

                if ($alternativeUnit && $alternativeUnit === $baseUnit) {
                    $validator->errors()->add(
                        "alternative_unit_conversions.$index.unit",
                        'A unidade alternativa nao pode ser igual a unidade padrao do produto'
                    );
                }
            }
        });

        return $validator->validate();
    }
}
