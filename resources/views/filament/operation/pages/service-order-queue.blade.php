<x-operation.page title="Ordens de Serviço">
    <x-operation.panel>
        <x-mary-input wire:model.live.debounce.300ms="search" placeholder="Buscar número, cliente ou equipamento" icon="o-magnifying-glass" clearable aria-label="Buscar ordens" />
        @include('filament.operation.date-filters')
        <x-operation.list-tabs :active="$activeTab" :open="$openCount" :closed="$closedCount" :all="$allCount" />
    </x-operation.panel>
    <div class="rounded-box border border-base-300 bg-base-100 [&_hr]:border-base-300">
        @forelse ($orders as $order)
            <x-operation.record-item :record="$order" :title="'OS #'.$order['number']" :subtitle="$order['customer']" :status="$order['status']" :color="$order['status_color']" :amount="$order['total']" :details="array_filter([$order['equipment'], $order['technician'], $order['order_date'], $order['pix_payment']])">
                <x-mary-dropdown :id="'order-actions-'.$order['id']" right>
                    <x-slot:trigger class="btn btn-ghost btn-sm btn-circle" aria-label="Ações da OS {{ $order['number'] }}">
                        <x-mary-icon name="o-ellipsis-vertical" class="h-4 w-4" />
                    </x-slot:trigger>
                    <x-mary-menu-item :id="'close-order-'.$order['id']" title="Encerrar Ordem" icon="o-check-circle" :wire:click="'requestRowAction(\'close\', '.$order['id'].')'" :disabled="! $order['is_open']" />
                    <x-mary-menu-item :id="'cancel-order-'.$order['id']" title="Cancelar Ordem" icon="o-x-circle" :wire:click="'requestRowAction(\'cancel\', '.$order['id'].')'" :disabled="! $order['is_open']" />
                    <x-mary-menu-item :id="'duplicate-order-'.$order['id']" title="Duplicar" icon="o-document-duplicate" :wire:click="'requestRowAction(\'duplicate\', '.$order['id'].')'" />
                </x-mary-dropdown>
            </x-operation.record-item>
        @empty
            <x-mary-alert title="Nenhuma ordem encontrada." icon="o-inbox" class="bg-base-200" />
        @endforelse
    </div>
    <x-operation.pagination :paginator="$pagination" />
    <x-operation.fab label="Nova OS" kind="service-order" />

    <x-mary-modal wire:model="showRowActionModal" :title="match ($rowAction) { 'close' => 'Encerrar Ordem', 'cancel' => 'Cancelar Ordem', default => 'Duplicar Ordem' }" :subtitle="'OS #'.$selectedOrderNumber" class="backdrop-blur-sm">
        <p class="text-sm text-base-content/70">{{ $rowAction === 'duplicate' ? 'Será criada uma nova OS com os dados e serviços desta ordem.' : 'Confirme para continuar com esta ação.' }}</p>
        <x-slot:actions>
            <x-mary-button label="Voltar" @click="$wire.showRowActionModal = false" />
            <x-mary-button :label="$rowAction === 'duplicate' ? 'Duplicar' : 'Confirmar'" :class="$rowAction === 'cancel' ? 'btn-error' : 'btn-primary'" wire:click="confirmRowAction" spinner="confirmRowAction" />
        </x-slot:actions>
    </x-mary-modal>
</x-operation.page>
