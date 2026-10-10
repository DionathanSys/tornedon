<x-operation.theme>
    <x-mary-modal wire:model="showModal" :title="$kind === 'service-order' ? 'Nova ordem de serviço' : 'Nova requisição'" subtitle="Selecione o cliente para começar." box-class="max-w-lg" class="backdrop-blur-sm">
        @if ($showNewCustomer)
            <x-mary-form wire:submit="createCustomer" no-separator>
                <x-mary-input label="Nome do cliente" wire:model="newCustomer.name" required />
                <div class="grid grid-cols-2 gap-3">
                    <x-mary-select label="Tipo de documento" wire:model.live="newCustomer.document_type" :options="[['id' => 'cpf', 'name' => 'CPF'], ['id' => 'cnpj', 'name' => 'CNPJ']]" />
                    <x-mary-input label="Documento" wire:model="newCustomer.document_number" x-mask:dynamic="$wire.newCustomer.document_type === 'cpf' ? '999.999.999-99' : '99.999.999/9999-99'" required />
                </div>
                <x-mary-select label="Indicador de inscrição estadual" wire:model="newCustomer.state_tax_indicator" :options="[['id' => '1', 'name' => 'Contribuinte ICMS'], ['id' => '2', 'name' => 'Isento'], ['id' => '9', 'name' => 'Não contribuinte']]" />
                <x-mary-input label="Inscrição estadual" wire:model="newCustomer.state_tax_id" />
                <x-slot:actions>
                    <x-mary-button label="Voltar" @click="$wire.showNewCustomer = false" />
                    <x-mary-button label="Cadastrar cliente" class="btn-primary" type="submit" spinner="createCustomer" />
                </x-slot:actions>
            </x-mary-form>
        @else
            <x-mary-form wire:submit="create" no-separator>
                <x-mary-choices label="Cliente" wire:model="customerId" :options="$customers" search-function="searchCustomers" placeholder="Buscar nome ou documento" no-result-text="Nenhum cliente encontrado" single searchable debounce="300ms" escape-values />
                <x-mary-button label="Novo cliente" icon="o-user-plus" class="btn-ghost btn-sm" @click="$wire.showNewCustomer = true" />
                <p class="text-sm text-base-content/60">Os serviços e detalhes do atendimento são preenchidos na próxima tela.</p>
                <x-slot:actions>
                    <x-mary-button label="Voltar" @click="$wire.showModal = false" />
                    <x-mary-button :label="$kind === 'service-order' ? 'Criar OS' : 'Criar requisição'" icon="o-arrow-right" class="btn-primary" type="submit" spinner="create" />
                </x-slot:actions>
            </x-mary-form>
        @endif
    </x-mary-modal>
</x-operation.theme>
