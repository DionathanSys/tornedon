<?php

namespace App\Services\FiscalDocument\Validators;

use App\Enum\FiscalDocument\Status;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FiscalDocumentValidator
{
    private static function commonRules(): array
    {
        return [
            'invoice_id' => 'nullable|integer|exists:invoices,id',
            'issued_at' => 'required|date',
            'movement_at' => 'required|date',
            'document_type' => 'nullable|string|max:50',
            'document_key' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:50',
            'document_series' => 'nullable|string|max:10',
            'operation_type' => 'nullable|integer',
            'operation_nature' => 'nullable|string|max:255',
            'issue_purpose' => 'nullable|string|max:50',
            'is_final_consumer' => 'nullable|boolean',
            'buyer_presence_indicator' => 'nullable|boolean',
            'tax_observations' => 'nullable|string',
            'additional_tax_information' => 'nullable|string',
            'taxpayer_observations' => 'nullable|string',
            'additional_taxpayer_information' => 'nullable|string',
            'additional_purchase_information' => 'nullable|string',
            'freight_data' => 'nullable|array',
            'payment_data' => 'nullable|array',
            'tax_data' => 'nullable|array',
        ];
    }

    private static function messages(): array
    {
        return [
            'customer_id.required' => 'O cliente é obrigatório.',
            'customer_id.exists' => 'Cliente não encontrado.',
            'company_id.required' => 'A empresa é obrigatória.',
            'company_id.exists' => 'Empresa não encontrada.',
            'invoice_id.exists' => 'Fatura não encontrada.',
            'issued_at.required' => 'A data de emissão é obrigatória.',
            'issued_at.date' => 'A data de emissão deve ser uma data válida.',
            'movement_at.required' => 'A data de movimentação é obrigatória.',
            'movement_at.date' => 'A data de movimentação deve ser uma data válida.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'customer_id' => 'required|integer|exists:partners,id',
            'company_id' => 'required|integer|exists:companies,id',
            'status' => ['required', Rule::in(array_map(fn($s) => $s->value, Status::cases()))],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data, int $id): array
    {
        $rules = array_merge(self::commonRules(), [
            'customer_id' => 'sometimes|required|integer|exists:partners,id',
            'company_id' => 'sometimes|required|integer|exists:companies,id',
            'status' => ['sometimes', 'required', Rule::in(array_map(fn($s) => $s->value, Status::cases()))],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
