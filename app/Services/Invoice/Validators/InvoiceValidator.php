<?php

namespace App\Services\Invoice\Validators;

use App\Enum\Invoice\Status;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceValidator
{
    private static function commonRules(): array
    {
        return [
            'invoice_date' => 'required|date',
            'pending' => 'boolean',
            'confirmed' => 'boolean',
            'canceled' => 'boolean',
        ];
    }

    private static function messages(): array
    {
        return [
            'customer_id.required' => 'O cliente é obrigatório.',
            'customer_id.exists' => 'Cliente não encontrado.',
            'company_id.required' => 'A empresa é obrigatória.',
            'company_id.exists' => 'Empresa não encontrada.',
            'invoice_number.required' => 'O número da fatura é obrigatório.',
            'invoice_number.unique' => 'Já existe uma fatura com este número.',
            'invoice_date.required' => 'A data da fatura é obrigatória.',
            'invoice_date.date' => 'A data da fatura deve ser uma data válida.',
            'status.required' => 'O status é obrigatório.',
            'status.enum' => 'O status deve ser um valor válido.',
            'pending.boolean' => 'O campo "pending" deve ser verdadeiro ou falso.',
            'confirmed.boolean' => 'O campo "confirmed" deve ser verdadeiro ou falso.',
            'canceled.boolean' => 'O campo "canceled" deve ser verdadeiro ou falso.',
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
            'invoice_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('invoices', 'invoice_number'),
            ],
            'status' => ['required', Rule::enum(Status::class)],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data, int $invoiceId): array
    {
        $rules = array_merge(self::commonRules(), [
            'status' => ['sometimes', 'required', Rule::enum(Status::class)],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
