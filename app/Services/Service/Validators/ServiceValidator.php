<?php

namespace App\Services\Service\Validators;

use App\Enum\Product\Unit;
use App\Enum\Tax\IssExigibility;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceValidator
{
    /**
     * Regras de validacao compartilhadas entre criacao e atualizacao.
     */
    private static function commonRules(): array
    {
        $issValues = array_map(fn ($e) => $e->value, IssExigibility::cases());

        return [
            'description' => 'nullable|string|max:2000',
            'cost' => 'nullable|numeric|min:0',
            'category' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'requires_approval' => 'nullable|boolean',
            'accept_customer_discount' => 'nullable|boolean',
            'tax_classification' => 'nullable|string|max:255',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'nbs_code' => 'nullable|string|max:50',
            'cnae_code' => 'nullable|string|max:50',
            'municipal_tax_code' => 'nullable|string|max:50',
            'ncm_code' => 'nullable|string|max:10',
            'cfop_code' => 'nullable|string|max:5',
            'origin_code' => 'nullable|string|max:2',
            'unit_of_measure' => 'nullable|string|max:10',
            'iss_exigibility' => ['nullable', Rule::in($issValues)],
            'additional_info' => 'nullable|array',
        ];
    }

    /**
     * Valida dados para criacao de servico.
     *
     * @param  array  $data  Dados a validar
     * @return array Retorna dados validados
     *
     * @throws ValidationException Se a validacao falhar
     */
    public static function validateCreate(array $data): array
    {
        Log::debug('Iniciando validacao para criacao de servico', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'campos' => array_keys($data),
        ]);

        $rules = array_merge(self::commonRules(), [
            'service_code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('services', 'service_code')->where('company_id', $data['company_id'] ?? null),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services', 'name')->where('company_id', $data['company_id'] ?? null),
            ],
            'price' => 'required|numeric|min:0',
            'min_sale_price' => 'nullable|numeric|min:0|lte:price',
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualizacao de servico.
     *
     * @param  array  $data  Dados a validar
     * @param  int|null  $serviceId  ID do servico sendo atualizado
     * @param  int|null  $companyId  ID da empresa (para validacao de unicidade)
     * @return array Retorna dados validados
     *
     * @throws ValidationException Se a validacao falhar
     */
    public static function validateUpdate(array $data, ?int $serviceId = null, ?int $companyId = null): array
    {
        Log::debug('Iniciando validacao para atualizacao de servico', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'service_id' => $serviceId,
            'campos' => array_keys($data),
        ]);

        $unitValues = array_map(fn ($u) => $u->value, Unit::cases());

        $rules = array_merge(self::commonRules(), [
            'service_code' => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('services', 'service_code')
                    ->where('company_id', $companyId)
                    ->ignore($serviceId),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'unit_of_measure' => ['sometimes', 'required', 'string', Rule::in($unitValues)],
            'price' => 'sometimes|required|numeric|min:0',
            'min_sale_price' => 'sometimes|nullable|numeric|min:0',
        ]);

        if (isset($data['name']) && $companyId) {
            $rules['name'][] = Rule::unique('services', 'name')
                ->where('company_id', $companyId)
                ->ignore($serviceId);
        }

        if (isset($data['service_code']) && $companyId) {
            $rules['service_code'] = [
                'nullable',
                'string',
                'max:20',
                Rule::unique('services', 'service_code')
                    ->where('company_id', $companyId)
                    ->ignore($serviceId),
            ];
        }

        if (array_key_exists('min_sale_price', $data) && array_key_exists('price', $data)) {
            $rules['min_sale_price'] = 'sometimes|nullable|numeric|min:0|lte:price';
        }

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Mensagens de validacao compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'name.required' => 'E obrigatorio informar o nome do servico',
            'name.max' => 'O nome do servico nao pode ter mais de 255 caracteres',
            'name.unique' => 'Ja existe um servico com este nome para esta empresa',
            'description.max' => 'A descricao nao pode ter mais de 2000 caracteres',
            'unit_of_measure.required' => 'E obrigatorio informar a unidade de medida',
            'unit_of_measure.in' => 'A unidade de medida informada e invalida',
            'price.required' => 'E obrigatorio informar o preco do servico',
            'price.numeric' => 'O preco deve ser um valor numerico',
            'price.min' => 'O preco nao pode ser negativo',
            'min_sale_price.numeric' => 'O preco minimo deve ser um valor numerico',
            'min_sale_price.min' => 'O preco minimo nao pode ser negativo',
            'min_sale_price.lte' => 'O preco minimo nao pode ser maior que o preco do servico',
            'cost.numeric' => 'O custo deve ser um valor numerico',
            'cost.min' => 'O custo nao pode ser negativo',
            'category.max' => 'A categoria nao pode ter mais de 255 caracteres',
            'is_active.boolean' => 'O campo ativo deve ser verdadeiro ou falso',
            'requires_approval.boolean' => 'O campo aprovacao deve ser verdadeiro ou falso',
            'accept_customer_discount.boolean' => 'O campo de desconto automatico deve ser verdadeiro ou falso',
            'tax_classification.max' => 'A classificacao fiscal nao pode ter mais de 255 caracteres',
            'tax_rate.numeric' => 'A aliquota de imposto deve ser um valor numerico',
            'tax_rate.min' => 'A aliquota de imposto nao pode ser negativa',
            'tax_rate.max' => 'A aliquota de imposto nao pode ser maior que 100%',
            'nbs_code.max' => 'O codigo NBS nao pode ter mais de 50 caracteres',
            'cnae_code.max' => 'O codigo CNAE nao pode ter mais de 50 caracteres',
            'municipal_tax_code.max' => 'O codigo de tributacao municipal nao pode ter mais de 50 caracteres',
            'iss_exigibility.in' => 'A exigibilidade do ISS informada e invalida',
            'additional_info.array' => 'As informacoes adicionais devem ser um objeto/array',
            'company_id.required' => 'E obrigatorio informar a empresa',
            'company_id.exists' => 'A empresa informada nao existe',
            'company_id.integer' => 'O ID da empresa deve ser um numero inteiro',
            'service_code.unique' => 'Ja existe um servico com este codigo',
            'service_code.max' => 'O codigo do servico nao pode ter mais de 20 caracteres',
        ];
    }
}
