<?php

namespace App\Services\FiscalDocument\Validators;

use App\Enum\FiscalDocument\BuyerPresenceIndicator;
use App\Enum\FiscalDocument\FreightModality;
use App\Enum\FiscalDocument\IssuePurpose;
use App\Enum\FiscalDocument\OperationNature;
use App\Enum\FiscalDocument\OperationType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regras de validação específicas para NF-e (cabeçalho).
 *
 * Campos obrigatórios para emissão de Nota Fiscal Eletrônica
 * que não se aplicam a outros modelos (ex: NFS-e).
 */
class NfeDocumentValidator
{
    private static function rules(): array
    {
        return [
            'operation_nature'          => ['required', Rule::enum(OperationNature::class)],
            'operation_type'            => ['required', Rule::enum(OperationType::class)],
            'issue_purpose'             => ['required', Rule::enum(IssuePurpose::class)],
            'is_final_consumer'         => 'required|boolean',
            'buyer_presence_indicator'  => ['required', Rule::enum(BuyerPresenceIndicator::class)],
            'freight_data'              => 'required|array',
            'freight_data.modalidade_frete' => ['required', Rule::enum(FreightModality::class)],
        ];
    }

    private static function updateRules(): array
    {
        return [
            'operation_nature'              => ['required', Rule::enum(OperationNature::class)],
            'operation_type'                => ['required', Rule::enum(OperationType::class)],
            'issue_purpose'                 => ['required', Rule::enum(IssuePurpose::class)],
            'is_final_consumer'             => 'required|boolean',
            'buyer_presence_indicator'      => ['required', Rule::enum(BuyerPresenceIndicator::class)],
            'freight_data'                  => 'sometimes|required|array',
            'freight_data.freight_modality' => ['required_with:freight_data', Rule::enum(FreightModality::class)],
        ];
    }

    private static function messages(): array
    {
        return [
            'operation_nature.required'         => 'A natureza da operação é obrigatória para NF-e.',
            'operation_nature.max'              => 'A natureza da operação não pode exceder 60 caracteres.',
            'operation_type.required'           => 'O tipo de operação é obrigatório para NF-e.',
            'operation_type.in'                 => 'O tipo de operação deve ser 0 (Entrada) ou 1 (Saída).',
            'issue_purpose.required'            => 'A finalidade de emissão é obrigatória para NF-e.',
            'issue_purpose.in'                  => 'A finalidade de emissão deve ser 1 (Normal), 2 (Complementar), 3 (Ajuste) ou 4 (Devolução).',
            'is_final_consumer.required'        => 'A indicação de consumidor final é obrigatória para NF-e.',
            'buyer_presence_indicator.required' => 'O indicador de presença do comprador é obrigatório para NF-e.',
            'buyer_presence_indicator.in'       => 'O indicador de presença do comprador é inválido.',
            'freight_data.required'             => 'Os dados de frete são obrigatórios para NF-e.',
            'freight_data.freight_modality.required'     => 'A modalidade de frete é obrigatória para NF-e.',
            'freight_data.freight_modality.required_with' => 'A modalidade de frete é obrigatória quando dados de frete são informados.',
            'freight_data.freight_modality.in'           => 'A modalidade de frete é inválida.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        return Validator::make($data, self::rules(), self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        return Validator::make($data, self::updateRules(), self::messages())->validate();
    }
}
