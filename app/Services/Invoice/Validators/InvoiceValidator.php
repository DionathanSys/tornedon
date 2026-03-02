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
            'total_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
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
            'total_amount.numeric' => 'O valor total deve ser numérico.',
            'total_amount.min' => 'O valor total não pode ser negativo.',
            'discount_amount.numeric' => 'O desconto deve ser numérico.',
            'discount_amount.min' => 'O desconto não pode ser negativo.',
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
            'status' => ['required', Rule::in(array_map(fn($s) => $s->value, Status::cases()))],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data, int $invoiceId): array
    {
        $rules = array_merge(self::commonRules(), [
            'customer_id' => 'sometimes|required|integer|exists:partners,id',
            'company_id' => 'sometimes|required|integer|exists:companies,id',
            'invoice_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('invoices', 'invoice_number')->ignore($invoiceId),
            ],
            'status' => ['sometimes', 'required', Rule::in(array_map(fn($s) => $s->value, Status::cases()))],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
