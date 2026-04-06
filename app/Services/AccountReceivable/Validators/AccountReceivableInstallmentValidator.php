<?php

namespace App\Services\AccountReceivable\Validators;

use App\Enum\AccountReceivable\Status;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountReceivableInstallmentValidator
{
    /**
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        return Validator::make($data, self::installmentRules(), self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        return Validator::make($data, self::installmentUpdateRules(), self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validatePayment(array $data): array
    {
        return Validator::make($data, self::paymentRules(), self::messages())->validate();
    }

    private static function installmentRules(): array
    {
        return [
            'account_receivable_id' => ['required', 'integer', 'exists:account_receivables,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'sequence_number' => ['required', 'string', 'max:3'],
            'due_date' => ['required', 'date'],
            'due_amount' => ['required', 'numeric', 'min:0'],
            'balance_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_column(Status::cases(), 'value'))],
            'received_date' => ['nullable', 'date'],
            'original_amount' => ['nullable', 'numeric', 'min:0'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'],
            'fine_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'received_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_account_id' => self::bankAccountRule(true),
            'financial_category_id' => ['nullable', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private static function installmentUpdateRules(): array
    {
        $rules = self::installmentRules();

        foreach (['account_receivable_id', 'company_id', 'sequence_number', 'due_date', 'due_amount', 'balance_amount', 'status'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        foreach (['received_date', 'original_amount', 'interest_amount', 'fine_amount', 'discount_amount', 'received_amount', 'bank_account_id', 'financial_category_id', 'cost_center_id', 'notes'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }

    private static function paymentRules(): array
    {
        return [
            'account_receivable_installment_id' => ['required', 'integer', 'exists:account_receivable_installments,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'],
            'fine_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_account_id' => self::bankAccountRule(false),
            'notes' => ['nullable', 'string'],
        ];
    }

    private static function bankAccountRule(bool $nullable): array
    {
        $rules = $nullable ? ['nullable', 'integer'] : ['sometimes', 'nullable', 'integer'];

        if (Schema::hasTable('bank_accounts')) {
            $rules[] = Rule::exists('bank_accounts', 'id');
        }

        return $rules;
    }

    private static function messages(): array
    {
        return [
            'account_receivable_id.required' => 'A conta a receber e obrigatoria.',
            'account_receivable_id.exists' => 'Conta a receber nao encontrada.',
            'account_receivable_installment_id.required' => 'A parcela e obrigatoria.',
            'account_receivable_installment_id.exists' => 'Parcela nao encontrada.',
            'company_id.required' => 'A empresa e obrigatoria.',
            'company_id.exists' => 'Empresa nao encontrada.',
            'sequence_number.required' => 'A sequencia da parcela e obrigatoria.',
            'due_date.required' => 'A data de vencimento e obrigatoria.',
            'due_date.date' => 'A data de vencimento deve ser valida.',
            'due_amount.required' => 'O valor da parcela e obrigatorio.',
            'due_amount.numeric' => 'O valor da parcela deve ser numerico.',
            'due_amount.min' => 'O valor da parcela nao pode ser negativo.',
            'balance_amount.required' => 'O saldo da parcela e obrigatorio.',
            'balance_amount.numeric' => 'O saldo da parcela deve ser numerico.',
            'balance_amount.min' => 'O saldo da parcela nao pode ser negativo.',
            'payment_date.required' => 'A data de recebimento e obrigatoria.',
            'payment_date.date' => 'A data de recebimento deve ser valida.',
            'amount.required' => 'O valor do recebimento e obrigatorio.',
            'amount.numeric' => 'O valor do recebimento deve ser numerico.',
            'amount.gt' => 'O valor do recebimento deve ser maior que zero.',
            'bank_account_id.exists' => 'Conta bancaria nao encontrada.',
            'status.required' => 'O status e obrigatorio.',
            'status.in' => 'Status de parcela invalido.',
        ];
    }
}
