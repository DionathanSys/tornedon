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
     * Regras de validação compartilhadas entre criação e atualização.
     */
    private static function commonRules(): array
    {
        $issValues = array_map(fn ($e) => $e->value, IssExigibility::cases());

        return [
            'description'        => 'nullable|string|max:2000',
            'cost'               => 'nullable|numeric|min:0',
            'category'           => 'nullable|string|max:255',
            'is_active'          => 'nullable|boolean',
            'requires_approval'  => 'nullable|boolean',
            'tax_classification' => 'nullable|string|max:255',
            'tax_rate'           => 'nullable|numeric|min:0|max:100',
            'nbs_code'           => 'nullable|string|max:50',
            'cnae_code'          => 'nullable|string|max:50',
            'municipal_tax_code' => 'nullable|string|max:50',
            'iss_exigibility'    => ['nullable', Rule::in($issValues)],
            'additional_info'    => 'nullable|array',
        ];
    }

    /**
     * Valida dados para criação de serviço.
     *
     * @param  array  $data  Dados a validar
     * @return array  Retorna dados validados
     *
     * @throws ValidationException Se a validação falhar
     */
    public static function validateCreate(array $data): array
    {
        Log::debug('Iniciando validação para criação de serviço', [
            'metodo' => __METHOD__ . '@' . __LINE__,
            'campos' => array_keys($data),
        ]);

        $rules = array_merge(self::commonRules(), [
            'service_code'       => [
                'required',
                'string',
                'max:20',
                Rule::unique('services', 'service_code')->where('company_id', $data['company_id'] ?? null),
            ],
            'name'               => [
                'required',
                'string',
                'max:255',
                Rule::unique('services', 'name')->where('company_id', $data['company_id'] ?? null),
            ],
            'price'              => 'required|numeric|min:0',
            'company_id'         => 'required|integer|exists:companies,id',
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Valida dados para atualização de serviço.
     *
     * @param  array     $data       Dados a validar
     * @param  int|null  $serviceId  ID do serviço sendo atualizado
     * @param  int|null  $companyId  ID da empresa (para validação de unicidade)
     * @return array     Retorna dados validados
     *
     * @throws ValidationException Se a validação falhar
     */
    public static function validateUpdate(array $data, ?int $serviceId = null, ?int $companyId = null): array
    {
        Log::debug('Iniciando validação para atualização de serviço', [
            'metodo'     => __METHOD__ . '@' . __LINE__,
            'service_id' => $serviceId,
            'campos'     => array_keys($data),
        ]);

        $unitValues = array_map(fn ($u) => $u->value, Unit::cases());

        $rules = array_merge(self::commonRules(), [
            'service_code'       => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('services', 'service_code')
                    ->where('company_id', $companyId)
                    ->ignore($serviceId),
            ],
            'name'               => [
                'sometimes',
                'required',
                'string',
                'max:255',
            ],
            'unit_of_measure'    => ['sometimes', 'required', 'string', Rule::in($unitValues)],
            'price'              => 'sometimes|required|numeric|min:0',
        ]);

        // Adiciona validação de unicidade de name apenas se o campo estiver presente nos dados e tiver company_id
        if (isset($data['name']) && $companyId) {
            $rules['name'][] = Rule::unique('services', 'name')
                ->where('company_id', $companyId)
                ->ignore($serviceId);
        }

        // Adiciona validação de service_code apenas se o campo estiver presente nos dados
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

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * Mensagens de validação compartilhadas.
     */
    private static function messages(): array
    {
        return [
            'name.required'              => 'É obrigatório informar o nome do serviço',
            'name.max'                   => 'O nome do serviço não pode ter mais de 255 caracteres',
            'name.unique'                => 'Já existe um serviço com este nome para esta empresa',
            'description.max'            => 'A descrição não pode ter mais de 2000 caracteres',
            'unit_of_measure.required'   => 'É obrigatório informar a unidade de medida',
            'unit_of_measure.in'         => 'A unidade de medida informada é inválida',
            'price.required'             => 'É obrigatório informar o preço do serviço',
            'price.numeric'              => 'O preço deve ser um valor numérico',
            'price.min'                  => 'O preço não pode ser negativo',
            'cost.numeric'               => 'O custo deve ser um valor numérico',
            'cost.min'                   => 'O custo não pode ser negativo',
            'category.max'               => 'A categoria não pode ter mais de 255 caracteres',
            'is_active.boolean'          => 'O campo ativo deve ser verdadeiro ou falso',
            'requires_approval.boolean'  => 'O campo aprovação deve ser verdadeiro ou falso',
            'tax_classification.max'     => 'A classificação fiscal não pode ter mais de 255 caracteres',
            'tax_rate.numeric'           => 'A alíquota de imposto deve ser um valor numérico',
            'tax_rate.min'               => 'A alíquota de imposto não pode ser negativa',
            'tax_rate.max'               => 'A alíquota de imposto não pode ser maior que 100%',
            'nbs_code.max'               => 'O código NBS não pode ter mais de 50 caracteres',
            'cnae_code.max'              => 'O código CNAE não pode ter mais de 50 caracteres',
            'municipal_tax_code.max'     => 'O código de tributação municipal não pode ter mais de 50 caracteres',
            'iss_exigibility.in'         => 'A exigibilidade do ISS informada é inválida',
            'additional_info.array'      => 'As informações adicionais devem ser um objeto/array',
            'company_id.required'        => 'É obrigatório informar a empresa',
            'company_id.exists'          => 'A empresa informada não existe',
            'company_id.integer'         => 'O ID da empresa deve ser um número inteiro',
            'service_code.unique'        => 'Já existe um serviço com este código',
            'service_code.max'           => 'O código do serviço não pode ter mais de 20 caracteres',
        ];
    }
}
