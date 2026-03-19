<?php

namespace App\Services\FiscalDocument\Validators\Items;

use App\Enum\Product\Origin;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regras de validação específicas para itens de NF-e.
 *
 * Valida campos obrigatórios de cada item da Nota Fiscal Eletrônica:
 * produto, NCM, CFOP, origem, quantidades, preços e dados tributários (ICMS, PIS, COFINS).
 */
class NfeItemValidator
{
    private static function rules(): array
    {
        return [
            'items'                                         => 'required|array|min:1',
            'items.*.product_id'                            => 'required|integer|exists:products,id',
            'items.*.product_code'                          => 'required|string|max:60',
            'items.*.description'                           => 'nullable|string|max:255',
            'items.*.ncm_code'                              => 'required|string|size:8',
            'items.*.cest_code'                             => 'nullable|string|max:9',
            'items.*.barcode'                               => 'nullable|string|max:60',
            'items.*.cfop_code'                             => 'required|string|size:4',
            'items.*.product_origin'                        => 'required|in:0,1,2,3,4,5,6,7,8',
            'items.*.quantity'                              => 'required|numeric|min:0.0001',
            'items.*.unit_price'                            => 'required|numeric|min:0',
            'items.*.total_price'                           => 'required|numeric|min:0',
            'items.*.unit_of_measure'                       => 'required|string|max:6',
            'items.*.taxable_unit'                          => 'nullable|string|max:6',
            'items.*.taxable_quantity'                      => 'nullable|numeric|min:0.0001',
            'items.*.taxable_unit_price'                    => 'nullable|numeric|min:0',
            'items.*.discount_amount'                       => 'nullable|numeric|min:0',
            'items.*.freight_amount'                        => 'nullable|numeric|min:0',
            'items.*.insurance_amount'                      => 'nullable|numeric|min:0',
            'items.*.other_expenses_amount'                 => 'nullable|numeric|min:0',
            'items.*.additional_information'                => 'nullable|string|max:500',
            'items.*.tax_data'                              => 'required|array',
            'items.*.tax_data.imposto'                      => 'required|array',
            'items.*.tax_data.imposto.icms'                 => 'required|array',
            'items.*.tax_data.imposto.icms.situacao_tributaria' => 'required|string',
            'items.*.tax_data.imposto.pis'                  => 'required|array',
            'items.*.tax_data.imposto.pis.situacao_tributaria'  => 'required|string',
            'items.*.tax_data.imposto.cofins'               => 'required|array',
            'items.*.tax_data.imposto.cofins.situacao_tributaria' => 'required|string',
        ];
    }

    private static function messages(): array
    {
        return [
            'items.required'                            => 'A NF-e deve conter pelo menos um item.',
            'items.min'                                 => 'A NF-e deve conter pelo menos um item.',
            'items.*.product_id.required'               => 'O produto é obrigatório para itens de NF-e.',
            'items.*.product_id.exists'                 => 'Produto não encontrado no item :position.',
            'items.*.product_code.required'             => 'O código do produto é obrigatório no item :position.',
            'items.*.ncm_code.required'                 => 'O código NCM é obrigatório no item :position.',
            'items.*.ncm_code.size'                     => 'O código NCM deve ter exatamente 8 dígitos no item :position.',
            'items.*.cfop_code.required'                => 'O código CFOP é obrigatório no item :position.',
            'items.*.cfop_code.size'                    => 'O código CFOP deve ter exatamente 4 dígitos no item :position.',
            'items.*.product_origin.required'           => 'A origem do produto é obrigatória no item :position.',
            'items.*.product_origin.in'                 => 'A origem do produto é inválida no item :position.',
            'items.*.quantity.required'                  => 'A quantidade é obrigatória no item :position.',
            'items.*.quantity.min'                       => 'A quantidade deve ser maior que zero no item :position.',
            'items.*.unit_price.required'                => 'O preço unitário é obrigatório no item :position.',
            'items.*.unit_price.min'                     => 'O preço unitário não pode ser negativo no item :position.',
            'items.*.total_price.required'               => 'O preço total é obrigatório no item :position.',
            'items.*.total_price.min'                    => 'O preço total não pode ser negativo no item :position.',
            'items.*.unit_of_measure.required'           => 'A unidade de medida é obrigatória no item :position.',
            'items.*.tax_data.required'                  => 'Os dados tributários são obrigatórios no item :position.',
            'items.*.tax_data.imposto.required'          => 'Os impostos são obrigatórios no item :position.',
            'items.*.tax_data.imposto.icms.required'     => 'O ICMS é obrigatório no item :position.',
            'items.*.tax_data.imposto.icms.situacao_tributaria.required' => 'A situação tributária do ICMS é obrigatória no item :position.',
            'items.*.tax_data.imposto.pis.required'      => 'O PIS é obrigatório no item :position.',
            'items.*.tax_data.imposto.pis.situacao_tributaria.required'  => 'A situação tributária do PIS é obrigatória no item :position.',
            'items.*.tax_data.imposto.cofins.required'   => 'O COFINS é obrigatório no item :position.',
            'items.*.tax_data.imposto.cofins.situacao_tributaria.required' => 'A situação tributária do COFINS é obrigatória no item :position.',
        ];
    }

    /**
     * Valida os itens de uma NF-e em lote (usado antes da emissão).
     *
     * @throws ValidationException
     */
    public static function validate(array $data): array
    {
        return Validator::make($data, self::rules(), self::messages())->validate();
    }

    /**
     * Regras para criação de um item individual.
     * Campos fiscais (NCM, CFOP, impostos) são nullable na criação — preenchidos antes da emissão.
     */
    private static function createRules(): array
    {
        return [
            'fiscal_document_id'                            => 'required|integer|exists:fiscal_documents,id',
            'product_id'                                    => 'required|integer|exists:products,id',
            'product_code'                                  => 'required|string|max:60',
            'description'                                   => 'required|string|max:255',
            'item_number'                                   => 'required|integer|min:1',
            'ncm_code'                                      => 'nullable|string|size:8',
            'cest_code'                                     => 'nullable|string|max:9',
            'barcode'                                       => 'nullable|string|max:60',
            'cfop_code'                                     => 'nullable|string|size:4',
            'product_origin'                                => ['nullable', Rule::enum(Origin::class)],
            'quantity'                                      => 'required|numeric|min:0.0001',
            'unit_price'                                    => 'required|numeric|min:0',
            'total_price'                                   => 'required|numeric|min:0',
            'unit_of_measure'                               => 'required|string|max:6',
            'taxable_unit'                                  => 'nullable|string|max:6',
            'taxable_quantity'                              => 'nullable|numeric|min:0.0001',
            'taxable_unit_price'                            => 'nullable|numeric|min:0',
            'discount_amount'                               => 'nullable|numeric|min:0',
            'freight_amount'                                => 'nullable|numeric|min:0',
            'insurance_amount'                              => 'nullable|numeric|min:0',
            'other_expenses_amount'                         => 'nullable|numeric|min:0',
            'additional_information'                        => 'nullable|string|max:500',
            'tax_data'                                      => 'nullable|array',
        ];
    }

    private static function createMessages(): array
    {
        return [
            'fiscal_document_id.required'   => 'O documento fiscal é obrigatório.',
            'fiscal_document_id.exists'     => 'Documento fiscal não encontrado.',
            'product_id.exists'             => 'Produto não encontrado.',
            'product_code.max'              => 'O código do produto não pode exceder 60 caracteres.',
            'service_id.exists'             => 'Serviço não encontrado.',
            'ncm_code.size'                 => 'O código NCM deve ter exatamente 8 dígitos.',
            'cfop_code.size'                => 'O código CFOP deve ter exatamente 4 dígitos.',
            'description.required'          => 'A descrição do produto é obrigatória.',
            'product_origin.enum'           => 'A origem do produto é inválida.',
            'quantity.required'             => 'A quantidade é obrigatória.',
            'quantity.min'                  => 'A quantidade deve ser maior que zero.',
            'unit_price.required'           => 'O preço unitário é obrigatório.',
            'unit_price.min'                => 'O preço unitário não pode ser negativo.',
            'total_price.required'          => 'O preço total é obrigatório.',
            'total_price.min'               => 'O preço total não pode ser negativo.',
            'unit_of_measure.required'      => 'A unidade de medida é obrigatória.',
        ];
    }

    /**
     * Regras para atualização de um item individual.
     * Campos fiscais (NCM, CFOP, impostos) são nullable — mesmas regras da criação com sometimes.
     */
    private static function updateRules(): array
    {
        return [
            'product_id'                                    => 'sometimes|nullable|integer|exists:products,id',
            'product_code'                                  => 'sometimes|required|string|max:60',
            'description'                                   => 'sometimes|nullable|string|max:255',
            'service_id'                                    => 'sometimes|nullable|integer|exists:services,id',
            'item_number'                                   => 'sometimes|nullable|integer|min:1',
            'ncm_code'                                      => 'sometimes|nullable|string|size:8',
            'cest_code'                                     => 'sometimes|nullable|string|max:9',
            'barcode'                                       => 'sometimes|nullable|string|max:60',
            'cfop_code'                                     => 'sometimes|nullable|string|size:4',
            'product_origin'                                => 'sometimes|nullable|in:0,1,2,3,4,5,6,7,8',
            'quantity'                                      => 'sometimes|required|numeric|min:0.0001',
            'unit_price'                                    => 'sometimes|required|numeric|min:0',
            'total_price'                                   => 'sometimes|required|numeric|min:0',
            'unit_of_measure'                               => 'sometimes|required|string|max:6',
            'taxable_unit'                                  => 'sometimes|nullable|string|max:6',
            'taxable_quantity'                              => 'sometimes|nullable|numeric|min:0.0001',
            'taxable_unit_price'                             => 'sometimes|nullable|numeric|min:0',
            'discount_amount'                               => 'sometimes|nullable|numeric|min:0',
            'freight_amount'                                => 'sometimes|nullable|numeric|min:0',
            'insurance_amount'                              => 'sometimes|nullable|numeric|min:0',
            'other_expenses_amount'                         => 'sometimes|nullable|numeric|min:0',
            'additional_information'                        => 'sometimes|nullable|string|max:500',
            'tax_data'                                      => 'sometimes|nullable|array',
        ];
    }

    /**
     * Valida um item individual na criação.
     *
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        return Validator::make($data, self::createRules(), self::createMessages())->validate();
    }

    /**
     * Valida itens em lote na criação (prefixo items.*).
     *
     * @throws ValidationException
     */
    public static function validateCreateMany(array $data): array
    {
        $rules = collect(self::createRules())
            ->mapWithKeys(fn ($rule, $key) => ["items.*.{$key}" => $rule])
            ->merge(['items' => 'required|array|min:1'])
            ->toArray();

        $messages = collect(self::createMessages())
            ->mapWithKeys(fn ($msg, $key) => ["items.*.{$key}" => $msg])
            ->merge(['items.required' => 'É necessário informar ao menos um item.'])
            ->merge(['items.min' => 'É necessário informar ao menos um item.'])
            ->toArray();

        return Validator::make($data, $rules, $messages)->validate();
    }

    /**
     * Valida um item individual na atualização.
     *
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        return Validator::make($data, self::updateRules(), self::createMessages())->validate();
    }
}
