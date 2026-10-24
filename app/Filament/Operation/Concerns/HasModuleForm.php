<?php

namespace App\Filament\Operation\Concerns;

use App\Enum\Payment\Condition;
use App\Enum\Payment\Method;
use App\Filament\Operation\OperationModules;
use App\Filament\Operation\OperationResourceSupport as Support;
use App\Models\CardInstitution;
use App\Models\CompanyPreference;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\Partner;
use App\Support\Financial\AccountEditGuard;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait HasModuleForm
{
    public function accountHeaderLocked(): bool
    {
        return $this->formEditing() && str_starts_with($this->moduleKey(), 'account_') && $this->record->payments()->exists();
    }

    public function counterpartyLocked(): bool
    {
        return $this->formEditing() && str_starts_with($this->moduleKey(), 'account_')
            && ! AccountEditGuard::canChangeCounterparty($this->record);
    }

    public array $data = [];

    public function formEditing(): bool
    {
        return isset($this->record);
    }

    protected function initializeModuleForm(?Model $record = null): void
    {
        $tenant = Filament::getTenant()->id;
        $scope = $this->categoryScope();
        $categories = FinancialCategory::optionsForCompany($tenant, $scope);
        $preferred = $scope === 'receivable' ? CompanyPreference::getDefaultReceivableFinancialCategoryId($tenant) : null;
        $this->data = [
            'is_manual_counterparty' => true, 'manual_counterparty_name' => '', 'customer_id' => null, 'supplier_id' => null, 'counterparty_partner_id' => null,
            'order_date' => today()->toDateString(), 'due_date' => today()->toDateString(), 'transaction_date' => today()->toDateString(),
            'payment_method' => CompanyPreference::getDefaultPaymentMethod($tenant) ?: Method::CASH->value,
            'payment_condition' => CompanyPreference::getDefaultPaymentCondition($tenant) ?: Condition::CASH->value,
            'financial_category_id' => $preferred && isset($categories[$preferred]) ? $preferred : array_key_first($categories),
            'financial_account_id' => FinancialAccount::defaultIdForCompany($tenant), 'direction' => 'inflow',
            'card_payment_profile_id' => CardInstitution::defaultIdForCompany($tenant), 'payment_date' => today()->toDateString(),
            'auto_register_receipt_on_due_date' => false, 'auto_receipt_financial_account_id' => FinancialAccount::defaultIdForCompany($tenant),
            'is_effective' => true, 'auto_register_payment_on_due_date' => false, 'auto_payment_financial_account_id' => FinancialAccount::defaultIdForCompany($tenant),
            'installment_count' => 1, 'installment_due_mode' => Condition::DAYS_30->value, 'installment_fixed_day' => 1, 'installment_interval_days' => 30, 'amount_input_mode' => 'total',
            'due_amount' => '', 'amount' => '', 'description' => '', 'document_number' => '', 'notes' => '', 'observations' => '',
        ];
        if (! $record) {
            return;
        }
        $this->data = [...$this->data, ...$record->attributesToArray()];
        $partnerField = $this->partnerField();
        $this->data['is_manual_counterparty'] = blank($record->{$partnerField}) && filled($record->manual_counterparty_name);
        foreach (['due_amount', 'amount'] as $field) {
            if ($record->getAttribute($field) !== null) {
                $this->data[$field] = number_format((float) $record->{$field}, 2, ',', '.');
            }
        }
        foreach (['order_date', 'due_date', 'transaction_date', 'payment_date'] as $field) {
            if ($record->getAttribute($field) !== null) {
                $this->data[$field] = $record->{$field}->toDateString();
            }
        }
        if ($this->moduleKey() === 'production_requests') {
            foreach (['card_payment_profile_id', 'payment_date', 'auto_register_receipt_on_due_date', 'auto_receipt_financial_account_id'] as $field) {
                $this->data[$field] = data_get($record->additional_info, $field, $this->data[$field]);
            }
        }
    }

    public function partnerField(): string
    {
        return match ($this->moduleKey()) {
            'account_payables' => 'supplier_id', 'cash_movements' => 'counterparty_partner_id', default => 'customer_id',
        };
    }

    private function categoryScope(): string
    {
        return match ($this->moduleKey()) {
            'account_payables' => 'payable', 'cash_movements' => 'cash_movement', default => 'receivable',
        };
    }

    public function formOptions(string $type): array
    {
        abort_unless(OperationModules::allows($this->moduleKey()), 403);
        $tenant = Filament::getTenant()->id;
        $options = match ($type) {
            'partners' => Partner::query()->whereHas('companies', function ($query) use ($tenant): void {
                $query->where('companies.id', $tenant)->where('company_partner.is_active', true);
                if ($this->moduleKey() === 'production_requests') {
                    $query->whereJsonContains('company_partner.type', 'customer');
                }
            })->orderBy('name')->pluck('name', 'id')->all(),
            'categories' => FinancialCategory::optionsForCompany($tenant, $this->categoryScope()),
            'accounts' => FinancialAccount::optionsForCompany($tenant),
            'cards' => CardInstitution::optionsForCompany($tenant),
            'methods' => Method::toSelectArray(), 'conditions' => Condition::toSelectArray(),
            'intervals' => [...Condition::installmentIntervalOptions(), 'fixed_day_of_month' => 'Dia fixo do mês', 'custom_interval_days' => 'Intervalo personalizado'],
            default => [],
        };

        return collect($options)->map(fn ($name, $id): array => ['id' => $id, 'name' => $name])->values()->all();
    }

    protected function moduleFormPayload(): array
    {
        $this->resetValidation();
        $module = $this->moduleKey();
        $editing = $this->formEditing();
        abort_if($this->accountHeaderLocked(), 403, 'O cabeçalho não pode ser alterado após uma baixa.');
        $data = $this->data;
        $partner = $this->partnerField();
        if ($module === 'production_requests' && $editing) {
            $data['customer_id'] = $this->record->customer_id;
            $data['manual_counterparty_name'] = $this->record->manual_counterparty_name;
            $data['is_manual_counterparty'] = blank($this->record->customer_id) && filled($this->record->manual_counterparty_name);
            $data['order_date'] = $this->record->order_date?->toDateString();
            $data['observations'] = $this->record->observations;
        }
        $manual = (bool) ($data['is_manual_counterparty'] ?? false);
        $data[$partner] = $manual ? null : ($data[$partner] ?? null);
        $data['manual_counterparty_name'] = $manual ? ($data['manual_counterparty_name'] ?? null) : null;
        if (in_array($module, ['production_requests', 'account_receivables'], true)) {
            if (($data['payment_method'] ?? null) !== Method::CREDIT_CARD->value) {
                $data['card_payment_profile_id'] = null;
                $data['payment_date'] = null;
            }
            if (! ($data['auto_register_receipt_on_due_date'] ?? false)) {
                $data['auto_receipt_financial_account_id'] = null;
            }
        }
        if ($module === 'account_payables' && ! ($data['auto_register_payment_on_due_date'] ?? false)) {
            $data['auto_payment_financial_account_id'] = null;
        }
        foreach (['amount', 'due_amount'] as $field) {
            if (filled($data[$field] ?? null)) {
                $data[$field] = Support::normalizeNumber($data[$field]);
            }
        }
        $rules = [
            'data.is_manual_counterparty' => 'required|boolean',
            'data.manual_counterparty_name' => [$manual && $module !== 'cash_movements' ? 'required' : 'nullable', 'string', 'max:255'],
            'data.'.$partner => [$manual || $module === 'cash_movements' ? 'nullable' : 'required', Rule::in(array_column($this->formOptions('partners'), 'id'))],
            'data.description' => ($module === 'cash_movements' ? 'required' : 'nullable').'|string|max:255',
        ];
        if ($module === 'production_requests' && $editing) {
            $rules['data.customer_id'] = ['nullable', 'integer'];
        }
        if ($module === 'cash_movements') {
            $rules += ['data.direction' => ['required', Rule::in(['inflow', 'outflow'])], 'data.transaction_date' => 'required|date', 'data.amount' => 'required|numeric|gt:0', 'data.financial_account_id' => ['required', Rule::in(array_column($this->formOptions('accounts'), 'id'))], 'data.notes' => 'nullable|string'];
        } elseif ($module === 'production_requests') {
            $rules += ['data.order_date' => 'required|date', 'data.observations' => 'nullable|string', 'data.payment_condition' => ['nullable', Rule::enum(Condition::class)]];
        } else {
            $rules += ['data.due_date' => 'required|date', 'data.due_amount' => 'required|numeric|min:0', 'data.document_number' => 'nullable|string|max:50'];
            if (! $editing) {
                $rules += ['data.installment_count' => 'required|integer|min:1|max:24', 'data.installment_due_mode' => ['required', Rule::in(array_column($this->formOptions('intervals'), 'id'))],
                    'data.installment_fixed_day' => 'nullable|required_if:data.installment_due_mode,fixed_day_of_month|integer|min:1|max:31', 'data.installment_interval_days' => 'nullable|required_if:data.installment_due_mode,custom_interval_days|integer|min:1|max:365'];
            }
        }
        if (! $editing || in_array($module, ['production_requests', 'cash_movements'], true)) {
            $rules['data.financial_category_id'] = ['required', Rule::in(array_column($this->formOptions('categories'), 'id'))];
        }
        if ($module !== 'cash_movements') {
            $rules['data.payment_method'] = ['nullable', Rule::enum(Method::class)];
        }
        if (in_array($module, ['production_requests', 'account_receivables'], true)) {
            $card = ($data['payment_method'] ?? null) === Method::CREDIT_CARD->value;
            $rules += ['data.card_payment_profile_id' => [$card ? 'required' : 'nullable', Rule::in(array_column($this->formOptions('cards'), 'id'))], 'data.payment_date' => ($card ? 'required' : 'nullable').'|date', 'data.auto_register_receipt_on_due_date' => 'boolean', 'data.auto_receipt_financial_account_id' => [($data['auto_register_receipt_on_due_date'] ?? false) ? 'required' : 'nullable', Rule::in(array_column($this->formOptions('accounts'), 'id'))]];
        }
        if ($module === 'account_payables') {
            $rules += ['data.is_effective' => 'boolean', 'data.auto_register_payment_on_due_date' => 'boolean', 'data.auto_payment_financial_account_id' => [($data['auto_register_payment_on_due_date'] ?? false) ? 'required' : 'nullable', Rule::in(array_column($this->formOptions('accounts'), 'id'))]];
            if (! $editing) {
                $rules['data.amount_input_mode'] = ['required', Rule::in(['total', 'per_installment'])];
            }
        }
        $validator = Validator::make(['data' => $data], $rules);
        $validator->after(function ($validator) use ($module, $data): void {
            if ($module === 'account_payables' && ($data['auto_register_payment_on_due_date'] ?? false) && ! ($data['is_effective'] ?? true)) {
                $validator->errors()->add('data.auto_register_payment_on_due_date', 'O pagamento automático exige uma conta efetivada.');
            }
        });
        $validated = $validator->validate()['data'];
        if ($editing && str_starts_with($module, 'account_')) {
            unset($validated['due_date'], $validated['due_amount']);
            $validated['counterparty_change_reason'] = $data['counterparty_change_reason'] ?? null;
        }
        $validated['company_id'] = Filament::getTenant()->id;
        if (! $editing && $module !== 'cash_movements') {
            $validated['status'] = $module === 'production_requests' ? 'open' : 'pending';
        }
        if ($module === 'production_requests') {
            $validated['additional_info'] = $editing ? ($this->record->additional_info ?? []) : [];
            foreach (['card_payment_profile_id', 'payment_date', 'auto_register_receipt_on_due_date', 'auto_receipt_financial_account_id'] as $field) {
                $validated['additional_info'][$field] = $validated[$field] ?? null;
                unset($validated[$field]);
            }
        }

        return $validated;
    }
}
