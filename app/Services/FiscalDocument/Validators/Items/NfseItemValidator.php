<?php

namespace App\Services\FiscalDocument\Validators\Items;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Regras de validacao especificas para itens de NFS-e.
 *
 * Valida campos obrigatorios de cada item de servico:
 * codigo do servico, descricao/discriminacao, valor, e campos tributarios (ISS).
 */
class NfseItemValidator
{
    private static function rules(): array
    {
        return [
            'items'                          => 'required|array|min:1|max:1',
            'items.*.description'            => 'required|string|max:2000',
            'items.*.quantity'               => 'required|numeric|min:0.0001',
            'items.*.unit_price'             => 'required|numeric|min:0',
            'items.*.total_price'            => 'required|numeric|min:0.01',
            'items.*.discount_amount'        => 'nullable|numeric|min:0',
            'items.*.service_code'           => 'nullable|string|max:20',
            'items.*.nbs_code'               => 'nullable|string|max:9',
            'items.*.iss_rate'               => 'nullable|numeric|min:0|max:100',
            'items.*.iss_exigibility'        => 'nullable|string|max:2',
            'items.*.additional_information' => 'nullable|string|max:500',
        ];
    }

    private static function messages(): array
    {
        return [
            'items.required'                => 'A NFS-e deve conter pelo menos um item de servico.',
            'items.min'                     => 'A NFS-e deve conter pelo menos um item de servico.',
            'items.max'                     => 'A NFS-e permite apenas um item de servico por documento.',
            'items.*.description.required'  => 'A discriminacao do servico e obrigatoria no item :position.',
            'items.*.description.max'       => 'A discriminacao do servico nao pode exceder 2000 caracteres no item :position.',
            'items.*.quantity.required'     => 'A quantidade e obrigatoria no item :position.',
            'items.*.quantity.min'          => 'A quantidade deve ser maior que zero no item :position.',
            'items.*.unit_price.required'   => 'O preco unitario e obrigatorio no item :position.',
            'items.*.total_price.required'  => 'O valor do servico e obrigatorio no item :position.',
            'items.*.total_price.min'       => 'O valor do servico deve ser maior que zero no item :position.',
            'items.*.iss_rate.max'          => 'A aliquota ISS nao pode exceder 100% no item :position.',
        ];
    }

    /**
     * Valida os itens de uma NFS-e em lote (usado antes da emissao).
     *
     * @throws ValidationException
     */
    public static function validate(array $data): array
    {
        return Validator::make($data, self::rules(), self::messages())->validate();
    }

    private static function createRules(): array
    {
        return [
            'fiscal_document_id'      => 'required|integer|exists:fiscal_documents,id',
            'service_id'              => 'nullable|integer|exists:services,id',
            'description'             => 'required|string|max:2000',
            'quantity'                => 'required|numeric|min:0.0001',
            'unit_price'              => 'required|numeric|min:0',
            'total_price'             => 'required|numeric|min:0.01',
            'unit_of_measure'         => 'nullable|string|max:6',
            'service_code'            => 'nullable|string|max:20',
            'nbs_code'                => 'nullable|string|max:9',
            'cnae_code'               => 'nullable|string|max:7',
            'municipal_tax_code'      => 'nullable|string|max:20',
            'discount_amount'         => 'nullable|numeric|min:0',
            'iss_rate'                => 'nullable|numeric|min:0|max:100',
            'iss_exigibility'         => 'nullable|string|max:2',
            'iss_withheld'            => 'nullable|boolean',
            'additional_information'  => 'nullable|string|max:500',
            'tax_data'                => 'nullable|array',
            'fiscal_snapshot'         => 'nullable|array',
        ];
    }

    private static function createMessages(): array
    {
        return [
            'fiscal_document_id.required' => 'O documento fiscal e obrigatorio.',
            'fiscal_document_id.exists'   => 'Documento fiscal nao encontrado.',
            'service_id.exists'           => 'Servico nao encontrado.',
            'description.required'        => 'A discriminacao do servico e obrigatoria.',
            'description.max'             => 'A discriminacao do servico nao pode exceder 2000 caracteres.',
            'quantity.required'           => 'A quantidade e obrigatoria.',
            'quantity.min'                => 'A quantidade deve ser maior que zero.',
            'unit_price.required'         => 'O preco unitario e obrigatorio.',
            'total_price.required'        => 'O valor do servico e obrigatorio.',
            'total_price.min'             => 'O valor do servico deve ser maior que zero.',
            'iss_rate.max'                => 'A aliquota ISS nao pode exceder 100%.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        return Validator::make($data, self::createRules(), self::createMessages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateCreateMany(array $data): array
    {
        $rules = collect(self::createRules())
            ->mapWithKeys(fn ($rule, $key) => ["items.*.{$key}" => $rule])
            ->merge(['items' => 'required|array|min:1|max:1'])
            ->toArray();

        $messages = collect(self::createMessages())
            ->mapWithKeys(fn ($msg, $key) => ["items.*.{$key}" => $msg])
            ->merge(['items.required' => 'E necessario informar ao menos um item de servico.'])
            ->merge(['items.min' => 'E necessario informar ao menos um item de servico.'])
            ->merge(['items.max' => 'A NFS-e permite apenas um item de servico por documento.'])
            ->toArray();

        return Validator::make($data, $rules, $messages)->validate();
    }

    private static function updateRules(): array
    {
        return [
            'service_id'              => 'sometimes|nullable|integer|exists:services,id',
            'description'             => 'sometimes|required|string|max:2000',
            'quantity'                => 'sometimes|required|numeric|min:0.0001',
            'unit_price'              => 'sometimes|required|numeric|min:0',
            'total_price'             => 'sometimes|required|numeric|min:0.01',
            'unit_of_measure'         => 'sometimes|nullable|string|max:6',
            'service_code'            => 'sometimes|nullable|string|max:20',
            'nbs_code'                => 'sometimes|nullable|string|max:9',
            'cnae_code'               => 'sometimes|nullable|string|max:7',
            'municipal_tax_code'      => 'sometimes|nullable|string|max:20',
            'discount_amount'         => 'sometimes|nullable|numeric|min:0',
            'iss_rate'                => 'sometimes|nullable|numeric|min:0|max:100',
            'iss_exigibility'         => 'sometimes|nullable|string|max:2',
            'iss_withheld'            => 'sometimes|nullable|boolean',
            'additional_information'  => 'sometimes|nullable|string|max:500',
            'tax_data'                => 'sometimes|nullable|array',
            'fiscal_snapshot'         => 'sometimes|nullable|array',
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        return Validator::make($data, self::updateRules(), self::createMessages())->validate();
    }
}
