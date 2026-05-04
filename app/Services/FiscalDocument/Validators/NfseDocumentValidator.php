<?php

namespace App\Services\FiscalDocument\Validators;

use App\Enum\FiscalDocument\NfseModel;
use App\Enum\FiscalDocument\OperationType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regras de validação específicas para NFS-e (cabeçalho).
 *
 * Campos obrigatórios para emissão de Nota Fiscal de Serviço Eletrônica
 * que não se aplicam a outros modelos (ex: NF-e).
 */
class NfseDocumentValidator
{
    private static function rules(): array
    {
        return [
            'nfse_model'  => ['required', Rule::enum(NfseModel::class)],
            'operation_type' => ['sometimes', Rule::enum(OperationType::class)],
            'customer_id' => 'required|integer|exists:partners,id',
            'company_id'  => 'required|integer|exists:companies,id',
            'issued_at'   => 'nullable|date',
        ];
    }

    private static function updateRules(): array
    {
        return [
            'nfse_model'  => ['sometimes', 'required', Rule::enum(NfseModel::class)],
            'operation_type' => ['sometimes', Rule::enum(OperationType::class)],
            'customer_id' => 'sometimes|required|integer|exists:partners,id',
            'company_id'  => 'sometimes|required|integer|exists:companies,id',
            'issued_at'   => 'sometimes|required|date',
        ];
    }

    private static function messages(): array
    {
        return [
            'nfse_model.required'  => 'O modelo NFS-e (municipal ou nacional) é obrigatório.',
            'customer_id.required' => 'O tomador do serviço é obrigatório para NFS-e.',
            'customer_id.exists'   => 'Tomador do serviço não encontrado.',
            'company_id.required'  => 'A empresa emitente é obrigatória para NFS-e.',
            'company_id.exists'    => 'Empresa emitente não encontrada.',
            'issued_at.date'       => 'A data de emissão deve ser uma data válida.',
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
