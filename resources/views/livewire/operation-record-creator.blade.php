<x-operation.theme>
    <x-mary-modal wire:model="showModal" :title="$showNewCustomer ? 'Cadastro de cliente' : ($kind === 'service-order' ? 'Nova ordem de serviço' : 'Nova requisição')" :subtitle="$showNewCustomer ? 'Consulte pelo CNPJ ou preencha os dados do cliente.' : 'Selecione o cliente para começar.'" box-class="max-w-lg" class="backdrop-blur-sm">
        @if ($showNewCustomer)
            <x-mary-form wire:key="operation-new-customer" wire:submit="createCustomer" no-separator>
                <div class="grid grid-cols-2 gap-3">
                    <x-mary-select label="Tipo de documento" wire:model.live="newCustomer.document_type" :options="[['id' => 'cpf', 'name' => 'CPF'], ['id' => 'cnpj', 'name' => 'CNPJ']]" />
                    <x-mary-input label="Documento" wire:model.blur="newCustomer.document_number" x-mask:dynamic="$wire.newCustomer.document_type === 'cpf' ? '999.999.999-99' : '99.999.999/9999-99'" required />
                </div>
                @if (($newCustomer['document_type'] ?? '') === 'cnpj')
                    <x-mary-button label="Buscar CNPJ" icon="o-magnifying-glass" class="btn-outline btn-sm" wire:click="lookupCnpj" spinner="lookupCnpj" />
                @endif
                @if ($cnpjLookup)
                    <x-mary-alert title="CNPJ localizado. Confira os dados antes de cadastrar." description="O endereço e o contato disponíveis na consulta também serão cadastrados." icon="o-check-circle" class="alert-success" />
                @endif
                <x-mary-input label="Nome do cliente" wire:model="newCustomer.name" required />
                <x-mary-select label="Indicador de inscrição estadual" wire:model="newCustomer.state_tax_indicator" :options="[['id' => '1', 'name' => 'Contribuinte ICMS'], ['id' => '2', 'name' => 'Isento'], ['id' => '9', 'name' => 'Não contribuinte']]" />
                <x-mary-input label="Inscrição estadual" wire:model="newCustomer.state_tax_id" />
                <x-slot:actions>
                    <x-mary-button label="Voltar" wire:click="$set('showNewCustomer', false)" />
                    <x-mary-button label="Cadastrar cliente" class="btn-primary" type="submit" spinner="createCustomer" />
                </x-slot:actions>
            </x-mary-form>
        @else
            <x-mary-form wire:key="operation-new-record" wire:submit="create" no-separator>
                <x-operation.search-select label="Cliente" wire:model="customerId" :options="$customers" search-function="searchCustomers" placeholder="Buscar nome ou documento" debounce="300ms" />
                <x-mary-button label="Novo cliente" icon="o-user-plus" class="btn-ghost btn-sm" wire:click="$set('showNewCustomer', true)" />
                <p class="text-sm text-base-content/60">Os serviços e detalhes do atendimento são preenchidos na próxima tela.</p>
                <x-slot:actions>
                    <x-mary-button label="Voltar" @click="$wire.showModal = false" />
                    <x-mary-button :label="$kind === 'service-order' ? 'Criar OS' : 'Criar requisição'" icon="o-arrow-right" class="btn-primary" type="submit" spinner="create" />
                </x-slot:actions>
            </x-mary-form>
        @endif
    </x-mary-modal>
</x-operation.theme>
