<?php

namespace App\Services\FiscalDocument\Validators;

use App\Enum\FiscalDocument\DocumentModel;
use App\Enum\FiscalDocument\Status;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Regras comuns de validação do cabeçalho do documento fiscal.
 * Aplica-se a todos os modelos (NF-e, NFS-e).
 */
class FiscalDocumentValidator
{
    public static function commonRules(): array
    {
        return [
            'invoice_id'                        => 'nullable|integer|exists:invoices,id',
            'issued_at'                         => 'nullable|date',
            'movement_at'                       => 'nullable|date',
            'document_key'                      => 'nullable|string|max:255',
            'document_number'                   => 'nullable|string|max:50',
            'document_series'                   => 'nullable|string|max:10',
            'tax_observations'                  => 'nullable|string',
            'additional_tax_information'        => 'nullable|string',
            'taxpayer_observations'             => 'nullable|string',
            'additional_taxpayer_information'   => 'nullable|string',
            'additional_purchase_information'   => 'nullable|string',
            'payment_data'                      => 'nullable|array',
            'tax_data'                          => 'nullable|array',
        ];
    }

    public static function commonMessages(): array
    {
        return [
            'customer_id.required'      => 'O cliente é obrigatório.',
            'customer_id.exists'        => 'Cliente não encontrado.',
            'company_id.required'       => 'A empresa é obrigatória.',
            'company_id.exists'         => 'Empresa não encontrada.',
            'invoice_id.exists'         => 'Fatura não encontrada.',
            'document_type.required'    => 'O tipo de documento é obrigatório.',
            'issued_at.required'        => 'A data de emissão é obrigatória.',
            'issued_at.date'            => 'A data de emissão deve ser uma data válida.',
            'movement_at.required'      => 'A data de movimentação é obrigatória.',
            'movement_at.date'          => 'A data de movimentação deve ser uma data válida.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'customer_id'   => 'required|integer|exists:partners,id',
            'company_id'    => 'required|integer|exists:companies,id',
            'document_type' => ['required', Rule::enum(DocumentModel::class)],
            'status'        => ['required', Rule::enum(Status::class)],
        ]);

        return Validator::make($data, $rules, self::commonMessages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data, int $id): array
    {
        $rules = array_merge(self::commonRules(), [
            'customer_id'   => 'sometimes|required|integer|exists:partners,id',
            'company_id'    => 'sometimes|required|integer|exists:companies,id',
            'document_type' => ['sometimes', 'required', Rule::enum(DocumentModel::class)],
            'status'        => ['sometimes', 'required', Rule::enum(Status::class)],
        ]);

        return Validator::make($data, $rules, self::commonMessages())->validate();
    }
}
