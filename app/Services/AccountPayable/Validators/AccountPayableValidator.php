<?php

namespace App\Services\AccountPayable\Validators;

use App\Enum\AccountPayable\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\FinancialAccount;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountPayableValidator
{
    private static function commonRules(): array
    {
        return [
            'sequence_number' => 'nullable|string|max:2',
            'due_date' => 'required|date',
            'paid_date' => 'nullable|date',
            'due_amount' => 'required|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',
            'bank_slip_number' => 'nullable|string|max:100',
            'note_number' => 'nullable|string|max:100',
            'document_number' => 'nullable|string|max:50',
            'description' => 'nullable|string|max:255',
            'is_effective' => 'nullable|boolean',
            'paid' => 'nullable|boolean',
            'type' => 'nullable|string|max:50',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'auto_register_payment_on_due_date' => 'nullable|boolean',
            'auto_payment_financial_account_id' => ['nullable', 'integer'],
        ];
    }

    private static function messages(): array
    {
        return [
            'supplier_id.required' => 'O fornecedor é obrigatório.',
            'supplier_id.exists' => 'Fornecedor não encontrado.',
            'company_id.required' => 'A empresa é obrigatória.',
            'company_id.exists' => 'Empresa não encontrada.',
            'fiscal_document_id.exists' => 'Documento fiscal não encontrado.',
            'due_date.required' => 'A data de vencimento é obrigatória.',
            'due_date.date' => 'A data de vencimento deve ser uma data válida.',
            'due_amount.required' => 'O valor a pagar é obrigatório.',
            'due_amount.numeric' => 'O valor a pagar deve ser numérico.',
            'due_amount.min' => 'O valor a pagar não pode ser negativo.',
            'paid_amount.numeric' => 'O valor pago deve ser numérico.',
            'paid_amount.min' => 'O valor pago não pode ser negativo.',
        ];
    }

    /**
     * @throws ValidationException
     */
    public static function validateCreate(array $data): array
    {
        $rules = array_merge(self::commonRules(), [
            'supplier_id' => 'required|integer|exists:partners,id',
            'company_id' => 'required|integer|exists:companies,id',
            'fiscal_document_id' => 'nullable|integer|exists:fiscal_documents,id',
            'sequence_number' => 'required|string|max:2',
            'status' => ['required', Rule::in(array_map(fn($s) => $s->value, Status::cases()))],
            'installment_count' => 'nullable|integer|min:1|max:24',
            'installment_due_mode' => ['nullable', Rule::in([
                ...array_keys(PaymentCondition::installmentIntervalOptions()),
                'fixed_day_of_month',
                'custom_interval_days',
            ])],
            'installment_fixed_day' => 'nullable|required_if:installment_due_mode,fixed_day_of_month|integer|min:1|max:31',
            'installment_interval_days' => 'nullable|required_if:installment_due_mode,custom_interval_days|integer|min:1|max:365',
        ]);
        $validator = Validator::make($data, $rules, self::messages());

        $validator->after(function ($validator) use ($data): void {
            $autoRegister = (bool) ($data['auto_register_payment_on_due_date'] ?? false);
            $isEffective = (bool) ($data['is_effective'] ?? true);

            if ($autoRegister && ! $isEffective) {
                $validator->errors()->add(
                    'auto_register_payment_on_due_date',
                    'O pagamento automatico so pode ser usado quando a conta estiver efetivada.'
                );
            }

            if (! $autoRegister) {
                return;
            }

            $accountId = $data['auto_payment_financial_account_id'] ?? null;

            if (blank($accountId)) {
                $validator->errors()->add(
                    'auto_payment_financial_account_id',
                    'Selecione a conta financeira para registrar o pagamento automatico.'
                );

                return;
            }

            $companyId = (int) ($data['company_id'] ?? 0);
            $account = FinancialAccount::query()
                ->where('company_id', $companyId)
                ->find((int) $accountId);

            if (! $account) {
                $validator->errors()->add('auto_payment_financial_account_id', 'Conta financeira nao encontrada.');
                return;
            }

            if (! $account->is_active) {
                $validator->errors()->add('auto_payment_financial_account_id', 'A conta financeira selecionada esta inativa.');
            }
        });

        return $validator->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data, int $id): array
    {
        $rules = array_merge(self::commonRules(), [
            'supplier_id' => 'sometimes|required|integer|exists:partners,id',
            'company_id' => 'sometimes|required|integer|exists:companies,id',
            'fiscal_document_id' => 'sometimes|nullable|integer|exists:fiscal_documents,id',
            'sequence_number' => 'sometimes|required|string|max:2',
            'status' => ['sometimes', 'required', Rule::in(array_map(fn($s) => $s->value, Status::cases()))],
        ]);

        return Validator::make($data, $rules, self::messages())->validate();
    }
}
