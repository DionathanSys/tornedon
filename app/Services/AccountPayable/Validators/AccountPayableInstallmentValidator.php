<?php

namespace App\Services\AccountPayable\Validators;

use App\Enum\AccountPayable\Status;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountPayableInstallmentValidator
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
            'account_payable_id' => ['required', 'integer', 'exists:account_payables,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'sequence_number' => ['required', 'string', 'max:3'],
            'due_date' => ['required', 'date'],
            'due_amount' => ['required', 'numeric', 'min:0'],
            'balance_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_column(Status::cases(), 'value'))],
            'paid_date' => ['nullable', 'date'],
            'original_amount' => ['nullable', 'numeric', 'min:0'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'],
            'fine_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_account_id' => self::bankAccountRule(true),
            'financial_category_id' => ['nullable', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private static function installmentUpdateRules(): array
    {
        $rules = self::installmentRules();

        foreach (['account_payable_id', 'company_id', 'sequence_number', 'due_date', 'due_amount', 'balance_amount', 'status'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        foreach (['paid_date', 'original_amount', 'interest_amount', 'fine_amount', 'discount_amount', 'paid_amount', 'bank_account_id', 'financial_category_id', 'cost_center_id', 'notes'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }

    private static function paymentRules(): array
    {
        return [
            'account_payable_installment_id' => ['required', 'integer', 'exists:account_payable_installments,id'],
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
            'account_payable_id.required' => 'A conta a pagar é obrigatória.',
            'account_payable_id.exists' => 'Conta a pagar não encontrada.',
            'account_payable_installment_id.required' => 'A parcela é obrigatória.',
            'account_payable_installment_id.exists' => 'Parcela não encontrada.',
            'company_id.required' => 'A empresa é obrigatória.',
            'company_id.exists' => 'Empresa não encontrada.',
            'sequence_number.required' => 'A sequência da parcela é obrigatória.',
            'due_date.required' => 'A data de vencimento é obrigatória.',
            'due_date.date' => 'A data de vencimento deve ser válida.',
            'due_amount.required' => 'O valor da parcela é obrigatório.',
            'due_amount.numeric' => 'O valor da parcela deve ser numérico.',
            'due_amount.min' => 'O valor da parcela não pode ser negativo.',
            'balance_amount.required' => 'O saldo da parcela é obrigatório.',
            'balance_amount.numeric' => 'O saldo da parcela deve ser numérico.',
            'balance_amount.min' => 'O saldo da parcela não pode ser negativo.',
            'payment_date.required' => 'A data de pagamento é obrigatória.',
            'payment_date.date' => 'A data de pagamento deve ser válida.',
            'amount.required' => 'O valor do pagamento é obrigatório.',
            'amount.numeric' => 'O valor do pagamento deve ser numérico.',
            'amount.gt' => 'O valor do pagamento deve ser maior que zero.',
            'bank_account_id.exists' => 'Conta bancária não encontrada.',
            'status.required' => 'O status é obrigatório.',
            'status.in' => 'Status de parcela inválido.',
        ];
    }
}
