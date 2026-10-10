<x-operation.page title="Ordens de Serviço">
    <x-operation.panel>
        <x-mary-input wire:model.live.debounce.300ms="search" placeholder="Buscar número, cliente ou equipamento" icon="o-magnifying-glass" clearable aria-label="Buscar ordens" />
        @include('filament.operation.date-filters')
        <x-operation.list-tabs :active="$activeTab" :open="$openCount" :closed="$closedCount" :all="$allCount" />
    </x-operation.panel>
    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100 [&_hr]:border-base-300">
        @forelse ($orders as $order)
            <x-operation.record-item :record="$order" :title="'OS #'.$order['number']" :subtitle="$order['customer']" :status="$order['status']" :color="$order['status_color']" :amount="$order['total']" :details="[$order['equipment'], $order['technician'], $order['order_date']]" />
        @empty
            <x-mary-alert title="Nenhuma ordem encontrada." icon="o-inbox" class="bg-base-200" />
        @endforelse
    </div>
    <x-operation.pagination :paginator="$pagination" />
    <x-operation.fab label="Nova OS" kind="service-order" />
</x-operation.page>
