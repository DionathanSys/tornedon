<?php

namespace App\Services\AccountReceivable\Validators;

use App\Enum\AccountReceivable\Status;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
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
        return Validator::make($data, self::installmentRules($data), self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data): array
    {
        return Validator::make($data, self::installmentUpdateRules($data), self::messages())->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validatePayment(array $data): array
    {
        return Validator::make($data, self::paymentRules($data), self::messages())->validate();
    }

    private static function installmentRules(array $data): array
    {
        return [
            'account_receivable_id' => ['required', 'integer', 'exists:account_receivables,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'sequence_number' => ['required', 'string', 'max:3'],
            'due_date' => ['required', 'date'],
            'due_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(array_map(fn (Status $status) => $status->value, Status::cases()))],
            'received_date' => ['nullable', 'date'],
            'original_amount' => ['nullable', 'numeric', 'min:0'],
            'interest_amount' => ['nullable', 'numeric', 'min:0'],
            'fine_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'received_amount' => ['nullable', 'numeric', 'min:0'],
            'balance_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_account_id' => self::bankAccountRule(true),
            'financial_category_id' => self::financialCategoryRule($data, 'receivable', true),
            'cost_center_id' => ['nullable', 'integer'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private static function installmentUpdateRules(array $data): array
    {
        $rules = self::installmentRules($data);

        foreach (['account_receivable_id', 'company_id', 'sequence_number', 'due_date', 'due_amount', 'status'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        foreach (['received_date', 'original_amount', 'interest_amount', 'fine_amount', 'discount_amount', 'received_amount', 'balance_amount', 'bank_account_id', 'financial_category_id', 'cost_center_id', 'description', 'notes'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules;
    }

    private static function paymentRules(array $data): array
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
            'financial_account_id' => self::financialAccountRule($data),
            'description' => ['nullable', 'string', 'max:255'],
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

    private static function financialCategoryRule(array $data, string $scope, bool $nullable): array
    {
        $rules = $nullable ? ['nullable', 'integer'] : ['required', 'integer'];
        $companyId = (int) ($data['company_id'] ?? 0);

        $rules[] = function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $scope): void {
            if ($value === null || $value === '') {
                return;
            }

            $category = FinancialCategory::query()
                ->where('company_id', $companyId)
                ->find($value);

            if (! $category) {
                $fail('Categoria financeira nao encontrada.');
                return;
            }

            if (! $category->is_active) {
                $fail('A categoria financeira selecionada esta inativa.');
                return;
            }

            if (! $category->isLeaf()) {
                $fail('Selecione uma subcategoria final para a classificacao financeira.');
                return;
            }

            if (! $category->allows($scope)) {
                $fail('A categoria financeira selecionada nao pode ser usada em contas a receber.');
            }
        };

        return $rules;
    }

    private static function financialAccountRule(array $data): array
    {
        $companyId = (int) ($data['company_id'] ?? 0);
        $rules = ['required_without:bank_account_id', 'nullable', 'integer'];

        $rules[] = function (string $attribute, mixed $value, \Closure $fail) use ($companyId): void {
            if ($value === null || $value === '') {
                return;
            }

            $account = FinancialAccount::query()
                ->where('company_id', $companyId)
                ->find($value);

            if (! $account) {
                $fail('Conta financeira nao encontrada.');
                return;
            }

            if (! $account->is_active) {
                $fail('A conta financeira selecionada esta inativa.');
            }
        };

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
            'payment_date.required' => 'A data de recebimento e obrigatoria.',
            'payment_date.date' => 'A data de recebimento deve ser valida.',
            'amount.required' => 'O valor do recebimento e obrigatorio.',
            'amount.numeric' => 'O valor do recebimento deve ser numerico.',
            'amount.gt' => 'O valor do recebimento deve ser maior que zero.',
            'bank_account_id.exists' => 'Conta bancaria nao encontrada.',
            'financial_account_id.required_without' => 'A conta financeira e obrigatoria.',
            'status.required' => 'O status e obrigatorio.',
            'status.in' => 'Status de parcela invalido.',
        ];
    }
}
