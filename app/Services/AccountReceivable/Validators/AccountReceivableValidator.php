<?php

namespace App\Services\AccountReceivable\Validators;

use App\Enum\AccountReceivable\Status;
use App\Enum\Payment\Condition as PaymentCondition;
use App\Enum\Payment\Method as PaymentMethod;
use App\Models\AccountReceivable;
use App\Models\CardInstitution;
use App\Models\FinancialAccount;
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
            'manual_counterparty_name' => 'nullable|string|max:255',
            'is_manual_counterparty' => 'nullable|boolean',
            'paid' => 'nullable|boolean',
            'auto_register_receipt_on_due_date' => 'nullable|boolean',
            'auto_receipt_financial_account_id' => 'nullable|integer',
            'type' => 'nullable|string|max:50',
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'card_payment_profile_id' => 'nullable|integer|exists:card_payment_profiles,id',
            'gross_amount' => 'nullable|numeric|min:0',
            'card_fee_percent_snapshot' => 'nullable|numeric|min:0',
            'card_fee_fixed_snapshot' => 'nullable|numeric|min:0',
            'card_fee_amount' => 'nullable|numeric|min:0',
            'net_amount' => 'nullable|numeric|min:0',
            'payment_date' => 'nullable|date',
            'settlement_days_snapshot' => 'nullable|integer|min:0',
            'expected_settlement_date' => 'nullable|date',
            'card_rule_snapshot' => 'nullable|array',
            'installment_count' => 'nullable|integer|min:1|max:24',
            'installment_due_mode' => ['nullable', Rule::in([
                ...array_keys(PaymentCondition::installmentIntervalOptions()),
                'fixed_day_of_month',
                'custom_interval_days',
            ])],
            'installment_fixed_day' => 'nullable|required_if:installment_due_mode,fixed_day_of_month|integer|min:1|max:31',
            'installment_interval_days' => 'nullable|required_if:installment_due_mode,custom_interval_days|integer|min:1|max:365',
        ];
    }

    private static function messages(): array
    {
        return [
            'customer_id.exists' => 'Cliente não encontrado.',
            'company_id.required' => 'A empresa é obrigatória.',
            'company_id.exists' => 'Empresa não encontrada.',
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
            'customer_id' => 'nullable|integer|exists:partners,id',
            'company_id' => 'required|integer|exists:companies,id',
            'invoice_id' => 'nullable|integer|exists:invoices,id',
            'fiscal_document_id' => 'nullable|integer|exists:fiscal_documents,id',
            'status' => ['required', Rule::in(array_map(fn ($s) => $s->value, Status::cases()))],
        ]);

        return self::makeValidator($data, $rules)->validate();
    }

    /**
     * @throws ValidationException
     */
    public static function validateUpdate(array $data, int $id): array
    {
        $record = AccountReceivable::findOrFail($id);
        // Use the persisted tenant for all cross-record validation.
        $data['company_id'] = $record->company_id;
        $defaults = [
            'customer_id' => $record->customer_id,
            'manual_counterparty_name' => $record->manual_counterparty_name,
            'payment_method' => $record->payment_method?->value,
            'card_payment_profile_id' => $record->card_payment_profile_id,
            'payment_date' => $record->payment_date?->toDateString(),
            'auto_register_receipt_on_due_date' => $record->auto_register_receipt_on_due_date,
            'auto_receipt_financial_account_id' => $record->auto_receipt_financial_account_id,
        ];
        $data = [...$defaults, ...$data];
        $rules = array_merge(self::commonRules(), [
            'customer_id' => 'sometimes|nullable|integer|exists:partners,id',
            'due_date' => 'sometimes|required|date',
            'due_amount' => 'sometimes|required|numeric|min:0',
            'company_id' => 'sometimes|required|integer|exists:companies,id',
            'invoice_id' => 'sometimes|nullable|integer|exists:invoices,id',
            'fiscal_document_id' => 'sometimes|nullable|integer|exists:fiscal_documents,id',
            'status' => ['sometimes', 'required', Rule::in(array_map(fn ($s) => $s->value, Status::cases()))],
        ]);

        return self::makeValidator($data, $rules)->validate();
    }

    private static function makeValidator(array $data, array $rules)
    {
        $validator = Validator::make($data, $rules, self::messages());

        $validator->after(function ($validator) use ($data): void {
            if ((bool) ($data['auto_register_receipt_on_due_date'] ?? false)) {
                $account = FinancialAccount::query()
                    ->where('company_id', (int) ($data['company_id'] ?? 0))
                    ->where('is_active', true)
                    ->find((int) ($data['auto_receipt_financial_account_id'] ?? 0));

                if (! $account) {
                    $validator->errors()->add('auto_receipt_financial_account_id', 'Selecione uma conta financeira ativa da empresa para a baixa automática.');
                }
            }
            $isManualCounterparty = (bool) ($data['is_manual_counterparty'] ?? false);
            $customerId = $data['customer_id'] ?? null;
            $manualCounterpartyName = trim((string) ($data['manual_counterparty_name'] ?? ''));
            $isManualCounterparty = $isManualCounterparty || ($manualCounterpartyName !== '' && blank($customerId));

            if ($isManualCounterparty) {
                if ($manualCounterpartyName === '') {
                    $validator->errors()->add('manual_counterparty_name', 'Informe o nome da contraparte avulsa.');
                }
            } elseif ((array_key_exists('customer_id', $data) || ! array_key_exists('invoice_id', $data)) && blank($customerId)) {
                $validator->errors()->add('customer_id', 'O cliente é obrigatório.');
            }

            $paymentMethod = $data['payment_method'] ?? null;

            if ($paymentMethod !== PaymentMethod::CREDIT_CARD->value) {
                return;
            }

            if (blank($data['card_payment_profile_id'] ?? null)) {
                $validator->errors()->add('card_payment_profile_id', 'A instituição de cartão é obrigatória para recebimentos em cartão.');
            }

            if (blank($data['payment_date'] ?? null)) {
                $validator->errors()->add('payment_date', 'A data do pagamento e obrigatoria para recebimentos em cartao de credito.');
            }

            $companyId = (int) ($data['company_id'] ?? 0);
            $profileId = (int) ($data['card_payment_profile_id'] ?? 0);

            if ($companyId <= 0 || $profileId <= 0) {
                return;
            }

            $profile = CardInstitution::query()
                ->where('company_id', $companyId)
                ->find($profileId);

            if (! $profile) {
                $validator->errors()->add('card_payment_profile_id', 'Instituição de cartão não encontrada para a empresa informada.');

                return;
            }

            if (! $profile->active) {
                $validator->errors()->add('card_payment_profile_id', 'A instituição de cartão selecionada está inativa.');
            }
        });

        return $validator;
    }
}
