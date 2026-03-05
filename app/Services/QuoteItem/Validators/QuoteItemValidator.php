<?php

namespace App\Services\QuoteItem\Validators;

use App\Enum\Quote\Destination;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuoteItemValidator
{
    /**
     * Regras comuns de validação (campos compartilhados entre create e update).
     */
    private static function commonRules(): array
    {
        return [
            'product_id'                 => 'nullable|required_without:service_id|exists:products,id',
            'service_id'                 => 'nullable|required_without:product_id|exists:services,id',
            'discount_percentage'        => 'nullable|numeric|min:0|max:100',
            'discount_amount'            => 'nullable|numeric|min:0',
            'technical_specifications'   => 'nullable|array',
            'estimated_production_hours' => 'nullable|numeric|min:0',
            'material_cost'              => 'nullable|numeric|min:0',
            'labor_cost'                 => 'nullable|numeric|min:0',
            'sequence'                   => 'nullable|integer|min:0',
            'additional_info'            => 'nullable|array',
        ];
    }

    /**
     * Mensagens de validação compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'quote_id.required'                 => 'É obrigatório informar o orçamento.',
            'quote_id.exists'                   => 'O orçamento informado não existe.',
            'product_id.required_without'       => 'É obrigatório informar o produto ou serviço.',
            'product_id.exists'                 => 'O produto informado não existe.',
            'service_id.required_without'       => 'É obrigatório informar o produto ou serviço.',
            'service_id.exists'                 => 'O serviço informado não existe.',
            'description.required'              => 'A descrição do item é obrigatória.',
            'description.max'                   => 'A descrição não pode ter mais de 2000 caracteres.',
            'quantity.required'                 => 'A quantidade é obrigatória.',
            'quantity.numeric'                  => 'A quantidade deve ser um número.',
            'quantity.min'                      => 'A quantidade deve ser maior que zero.',
            'unit_of_measure.required'          => 'A unidade de medida é obrigatória.',
            'destination.required'              => 'A finalidade é obrigatória.',
            'unit_price.required'               => 'O preço unitário é obrigatório.',
            'unit_price.numeric'                => 'O preço unitário deve ser um número.',
            'unit_price.min'                    => 'O preço unitário não pode ser negativo.',
            'discount_percentage.min'           => 'O desconto percentual não pode ser negativo.',
            'discount_percentage.max'           => 'O desconto percentual não pode ser maior que 100%.',
            'discount_amount.min'               => 'O valor do desconto não pode ser negativo.',
            'estimated_production_hours.min'    => 'As horas estimadas não podem ser negativas.',
            'material_cost.min'                 => 'O custo de material não pode ser negativo.',
            'labor_cost.min'                    => 'O custo de mão de obra não pode ser negativo.',
            'status.required'                   => 'O status é obrigatório.',
        ];
    }

    /**
     * Valida dados para criação de item de orçamento.
     *
     * @param  array  $data
     * @return array  Dados validados
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'quote_id'          => 'required|integer|exists:quotes,id',
            'description'       => 'nullable|string|max:2000',
            'destination'       => ['required', 'string', 'max:255', Rule::enum(Destination::class)],
            'quantity'          => 'required|numeric|min:0.001',
            'unit_of_measure'   => 'required|string|max:20',
            'unit_price'        => 'required|numeric|min:0',
            'status'            => 'required|string|max:50',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualização de item de orçamento.
     *
     * @param  array  $data
     * @return array  Dados validados
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'description'       => 'sometimes|nullable|string|max:2000',
            'destination'       => 'sometimes|required|string|max:255',
            'quantity'          => 'sometimes|required|numeric|min:0.001',
            'unit_of_measure'   => 'sometimes|required|string|max:20',
            'unit_price'        => 'sometimes|required|numeric|min:0',
            'status'            => 'sometimes|required|string|max:50',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
