<?php

namespace App\Services\PurchaseClosing\Validators;

use App\Enum\Payment\Method as PaymentMethod;
use App\Enum\PurchaseClosing\Status;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseClosingValidator
{
    /**
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        return Validator::make($data, self::baseRules(requireDocuments: true), self::messages())
            ->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        return Validator::make($data, self::baseRules(requireDocuments: true), self::messages())
            ->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateGenerateAccountPayable(array $data): array
    {
        return Validator::make($data, [
            'due_date' => 'required|date',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'description' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:50',
            'financial_category_id' => 'nullable|integer',
            'cost_center_id' => 'nullable|integer',
            'installment_count' => 'nullable|integer|min:1|max:24',
            'installment_due_mode' => 'nullable|string|max:50',
            'installment_fixed_day' => 'nullable|integer|min:1|max:31',
            'installment_interval_days' => 'nullable|integer|min:1|max:365',
            'amount_input_mode' => ['nullable', Rule::in(['total', 'per_installment'])],
        ])->validate();
    }

    private static function baseRules(bool $requireDocuments): array
    {
        $documentRules = [
            'documents' => [$requireDocuments ? 'required' : 'sometimes', 'array', 'min:1'],
            'documents.*.fiscal_document_id' => ['required', 'integer', 'distinct', 'exists:fiscal_documents,id'],
            'documents.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ];

        return [
            'company_id' => 'required|integer|exists:companies,id',
            'supplier_id' => 'required|integer|exists:partners,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reference' => 'nullable|string|max:100',
            'status' => ['nullable', Rule::enum(Status::class)],
            'notes' => 'nullable|string',
            ...$documentRules,
        ];
    }

    private static function messages(): array
    {
        return [
            'documents.required' => 'Selecione ao menos uma nota fiscal para o fechamento.',
            'documents.min' => 'Selecione ao menos uma nota fiscal para o fechamento.',
            'documents.*.fiscal_document_id.distinct' => 'Uma mesma nota fiscal não pode ser repetida no fechamento.',
        ];
    }
}
