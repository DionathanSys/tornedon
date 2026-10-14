@php
    $module = $this->moduleKey();
    $editing = $this->formEditing();
    $partner = $this->partnerField();
    $card = ($data['payment_method'] ?? null) === \App\Enum\Payment\Method::CREDIT_CARD->value;
    $cash = $module === 'cash_movements';
    $production = $module === 'production_requests';
@endphp
<div class="space-y-4">
    @if (! $production || ! $editing)
    <x-mary-checkbox label="Contraparte avulsa" wire:model.live="data.is_manual_counterparty" :disabled="$editing && $module !== 'account_receivables'" />
    @if ($data['is_manual_counterparty'] ?? false)
        <x-mary-input label="Nome da contraparte" wire:model="data.manual_counterparty_name" maxlength="255" />
    @else
        <x-operation.search-select :label="$module === 'account_payables' ? 'Fornecedor' : 'Cliente / parceiro'" :wire:model="'data.'.$partner" :options="$this->formOptions('partners')" offline />
    @endif
    @endif
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @if (! $production || ! $editing)
        <x-mary-input :label="$production ? 'Data do pedido' : ($cash ? 'Data do movimento' : 'Vencimento')" type="date" :wire:model="'data.'.($production ? 'order_date' : ($cash ? 'transaction_date' : 'due_date'))" />
        @endif
        @if (! $production)
            <x-mary-input label="Valor" prefix="R$" inputmode="decimal" :wire:model="'data.'.($cash ? 'amount' : 'due_amount')" />
        @endif
        @if ($cash)
            <x-mary-select label="Direção" wire:model="data.direction" :options="[['id' => 'inflow', 'name' => 'Entrada'], ['id' => 'outflow', 'name' => 'Saída']]" />
            <x-operation.search-select label="Conta financeira" wire:model="data.financial_account_id" :options="$this->formOptions('accounts')" offline />
        @else
            <x-operation.search-select label="Forma de pagamento" wire:model.live="data.payment_method" :options="$this->formOptions('methods')" offline />
        @endif
        @if (! $editing || $production || $cash)
            <x-operation.search-select label="Categoria financeira" wire:model="data.financial_category_id" :options="$this->formOptions('categories')" offline />
        @endif
        @if ($production && ! $card)
            <x-operation.search-select label="Condição de pagamento" wire:model="data.payment_condition" :options="$this->formOptions('conditions')" offline />
        @endif
        @if (! $production && ! $cash)
            <x-mary-input label="Documento" wire:model="data.document_number" maxlength="50" />
        @endif
    </div>
    @if (! $editing && ! $production && ! $cash)
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-mary-input label="Parcelas" type="number" min="1" max="24" wire:model.live="data.installment_count" />
            <x-operation.search-select label="Vencimentos das parcelas" wire:model.live="data.installment_due_mode" :options="$this->formOptions('intervals')" offline />
            @if (($data['installment_due_mode'] ?? '') === 'fixed_day_of_month')
                <x-mary-input label="Dia do mês" type="number" min="1" max="31" wire:model="data.installment_fixed_day" />
            @elseif (($data['installment_due_mode'] ?? '') === 'custom_interval_days')
                <x-mary-input label="Intervalo em dias" type="number" min="1" max="365" wire:model="data.installment_interval_days" />
            @endif
            @if ($module === 'account_payables')
                <x-mary-select label="Valor informado" wire:model="data.amount_input_mode" :options="[['id' => 'total', 'name' => 'Total da conta'], ['id' => 'per_installment', 'name' => 'Valor por parcela']]" />
            @endif
        </div>
    @endif
    @if ($card && in_array($module, ['production_requests', 'account_receivables'], true))
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <x-operation.search-select label="Instituição de cartão" wire:model="data.card_payment_profile_id" :options="$this->formOptions('cards')" offline />
            <x-mary-input label="Data da venda no cartão" type="date" wire:model="data.payment_date" />
        </div>
    @endif
    @if (in_array($module, ['production_requests', 'account_receivables'], true))
        <x-mary-checkbox label="Registrar recebimento automaticamente no vencimento" wire:model.live="data.auto_register_receipt_on_due_date" />
        @if ($data['auto_register_receipt_on_due_date'] ?? false)
            <x-operation.search-select label="Conta para recebimento automático" wire:model="data.auto_receipt_financial_account_id" :options="$this->formOptions('accounts')" offline />
        @endif
    @elseif ($module === 'account_payables')
        <x-mary-checkbox label="Conta efetivada" wire:model.live="data.is_effective" />
        <x-mary-checkbox label="Registrar pagamento automaticamente no vencimento" wire:model.live="data.auto_register_payment_on_due_date" />
        @if ($data['auto_register_payment_on_due_date'] ?? false)
            <x-operation.search-select label="Conta para pagamento automático" wire:model="data.auto_payment_financial_account_id" :options="$this->formOptions('accounts')" offline />
        @endif
    @endif
    @if ($production)
        @if (! $editing)
        <x-mary-textarea label="Observações" wire:model="data.observations" rows="3" />
        @endif
    @else
        <x-mary-input label="Descrição" wire:model="data.description" maxlength="255" />
        @if ($cash)
            <x-mary-textarea label="Observações" wire:model="data.notes" rows="3" />
        @endif
    @endif
</div>
