<?php

namespace App\Services\Invoice\Validators;

use App\Enum\Invoice\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoiceValidator
{
    private static function commonRules(): array
    {
        return [
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'payment_condition' => ['nullable', Rule::enum(PaymentCondition::class)],
            'pending' => 'boolean',
            'confirmed' => 'boolean',
            'canceled' => 'boolean',
        ];
    }

    private static function messages(): array
    {
        return [
            'customer_id.required' => 'O cliente Ã© obrigatÃ³rio.',
            'customer_id.exists' => 'Cliente nÃ£o encontrado.',
            'company_id.required' => 'A empresa Ã© obrigatÃ³ria.',
            'company_id.exists' => 'Empresa nÃ£o encontrada.',
            'invoice_number.required' => 'O nÃºmero da fatura Ã© obrigatÃ³rio.',
            'invoice_number.unique' => 'JÃ¡ existe uma fatura com este nÃºmero.',
            'invoice_date.required' => 'A data da fatura e obrigatoria.',
            'invoice_date.date' => 'A data da fatura deve ser uma data valida.',
            'payment_method.enum' => 'A forma de pagamento deve ser um valor vÃ¡lido.',
            'payment_condition.enum' => 'A condicao de pagamento deve ser um valor vÃ¡lido.',
            'status.required' => 'O status Ã© obrigatÃ³rio.',
            'status.enum' => 'O status deve ser um valor vÃ¡lido.',
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
                Rule::unique('invoices', 'invoice_number')
                    ->where('company_id', $data['company_id'] ?? null),
            ],
            'invoice_date' => 'required|date',
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
            'invoice_date' => 'sometimes|date',
            'status' => ['sometimes', 'required', Rule::enum(Status::class)],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
