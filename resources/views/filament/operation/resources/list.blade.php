@php
    $module = $this->moduleKey();
    $definition = \App\Filament\Operation\OperationResourceSupport::definition($module);
    $records = $this->records;
@endphp
<x-operation.page :title="$this->getTitle()">
    <x-operation.panel>
        <x-mary-input wire:model.live.debounce.300ms="search" placeholder="Buscar número, contraparte ou descrição" icon="o-magnifying-glass" clearable aria-label="Buscar registros" />
        @include('filament.operation.date-filters')
        <x-operation.list-tabs :active="$activeTab" :tabs="$this->tabs" />
    </x-operation.panel>
    @if ($module === 'production_requests')
        <x-mary-button label="Produtos em aberto" icon="o-chart-bar" :link="\App\Filament\Operation\OperationResourceSupport::resource($module)::getUrl('open-products-report')" class="btn-outline" />
    @endif
    <div class="rounded-box border border-base-300 bg-base-100 [&_hr]:border-base-300">
        @forelse ($records as $record)
            <x-operation.record-item :record="$record" :title="$record['title']" :subtitle="$record['subtitle']" :status="$record['status']" :color="$record['color']" :amount="$record['amount']" :details="$record['details']" :icon="\App\Filament\Operation\OperationModules::definitions()[$module]['icon']">
                @if ($record['can_pay'] || $record['can_delete'])
                    <x-mary-dropdown :id="'record-actions-'.$record['id']" right>
                        <x-slot:trigger class="btn btn-ghost btn-sm btn-circle" aria-label="Ações do registro">
                            <x-mary-icon name="o-ellipsis-vertical" class="h-4 w-4" />
                        </x-slot:trigger>
                        @if ($record['can_pay'])
                            <x-mary-menu-item :title="$module === 'account_receivables' ? 'Registrar recebimento' : 'Registrar pagamento'" icon="o-banknotes" :wire:click="'openRegisterPayment('.$record['id'].')'" />
                        @endif
                        @if ($record['can_delete'])
                            <x-mary-menu-item title="Excluir pedido" icon="o-trash" :wire:click="'requestDelete('.$record['id'].')'" />
                        @endif
                    </x-mary-dropdown>
                @endif
            </x-operation.record-item>
        @empty
            <x-mary-alert title="Nenhum registro encontrado." icon="o-inbox" class="bg-base-200" />
        @endforelse
    </div>
    <x-operation.pagination :paginator="$records" />
    <x-operation.fab :label="$definition['create']" :kind="$module" event="operation-create-module" />
    @include('filament.operation.resources.payment-modal')
    <x-mary-modal wire:model="showDeleteModal" title="Excluir pedido?" subtitle="Confirme para continuar." class="backdrop-blur-sm">
        <x-slot:actions>
            <x-mary-button label="Voltar" @click="$wire.showDeleteModal = false" />
            <x-mary-button label="Excluir" class="btn-error" wire:click="confirmDelete" spinner="confirmDelete" />
        </x-slot:actions>
    </x-mary-modal>
</x-operation.page>
