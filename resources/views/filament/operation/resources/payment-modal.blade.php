<x-mary-modal wire:model="showPaymentForm" :title="$this->moduleKey() === 'account_receivables' ? 'Registrar recebimento' : 'Registrar pagamento'" box-class="max-w-2xl" class="backdrop-blur-sm">
    <x-mary-form wire:submit="savePayment" no-separator>
        <div class="grid grid-cols-2 gap-4">
            <x-mary-input label="Data" type="date" wire:model="paymentData.payment_date" />
            <x-mary-input label="Valor" prefix="R$" inputmode="decimal" wire:model="paymentData.amount" />
            <x-mary-input label="Juros" prefix="R$" inputmode="decimal" wire:model="paymentData.interest_amount" />
            <x-mary-input label="Multa" prefix="R$" inputmode="decimal" wire:model="paymentData.fine_amount" />
            <x-mary-input label="Desconto" prefix="R$" inputmode="decimal" wire:model="paymentData.discount_amount" />
        </div>
        <x-operation.search-select label="Conta financeira" wire:model="paymentData.financial_account_id" :options="collect($this->financialAccountOptions)->map(fn ($name, $id) => ['id' => $id, 'name' => $name])->values()->all()" offline />
        <x-mary-input label="Descrição" wire:model="paymentData.description" />
        <x-mary-textarea label="Observações" wire:model="paymentData.notes" rows="2" />
        <x-slot:actions>
            <x-mary-button label="Voltar" @click="$wire.showPaymentForm = false" />
            <x-mary-button label="Confirmar" class="btn-primary" type="submit" spinner="savePayment" />
        </x-slot:actions>
    </x-mary-form>
</x-mary-modal>
