<?php

namespace App\Services\AccountReceivable\Validators;

use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Method as PaymentMethod;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountReceivableValidator
{
    private static function commonRules(): array
    {
        return [
            'due_date' => 'required|date',
            'paid_date' => 'nullable|date',
            'due_amount' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'document_number' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:255',
            'paid' => 'nullable|boolean',
            'type' => 'nullable|string|max:50',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
        ];
    }

    private static function messages(): array
    {
        return [
            'customer_id.required' => 'O cliente é obrigatório.',
            'customer_id.exists' => 'Cliente não encontrado.',
            'company_id.required' => 'A empresa é obrigatória.',
            'company_id.exists' => 'Empresa não encontrada.',
            'invoice_id.required' => 'A fatura é obrigatória.',
            'invoice_id.exists' => 'Fatura não encontrada.',
            'due_date.required' => 'A data de vencimento é obrigatória.',
            'due_date.date' => 'A data de vencimento deve ser uma data válida.',
            'due_amount.required' => 'O valor a receber é obrigatório.',
            'due_amount.numeric' => 'O valor a receber deve ser numérico.',
            'due_amount.min' => 'O valor a receber não pode ser negativo.',
            'paid_amount.numeric' => 'O valor recebido deve ser numérico.',
            'paid_amount.min' => 'O valor recebido não pode ser negativo.',
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
            'invoice_id' => 'required|integer|exists:invoices,id',
            'sequence_number' => 'required|string|max:2',
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
            'invoice_id' => 'sometimes|required|integer|exists:invoices,id',
            'sequence_number' => 'sometimes|required|string|max:2',
            'status' => ['sometimes', 'required', Rule::in(array_map(fn($s) => $s->value, Status::cases()))],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
