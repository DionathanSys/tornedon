<x-operation.page title="Ordens de Serviço">
    <x-mary-input wire:model.live.debounce.300ms="search" placeholder="Buscar número, cliente ou equipamento" icon="o-magnifying-glass" clearable aria-label="Buscar ordens" />
    <x-operation.list-tabs :active="$activeTab" :open="$openCount" :closed="$closedCount" :all="$allCount" />
    <div class="space-y-3">
        @forelse ($orders as $order)
            <a href="{{ $order['url'] }}" wire:navigate class="block" wire:key="order-{{ $order['number'] }}">
                <x-mary-card shadow>
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-bold">OS #{{ $order['number'] }}</span>
                        <x-operation.status :value="$order['status']" :color="$order['status_color']" />
                    </div>
                    <p class="mt-2 font-medium">{{ $order['customer'] }}</p>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm text-base-content/60">
                        <span>{{ $order['equipment'] }}</span><span>{{ $order['technician'] }}</span>
                        <span>{{ $order['order_date'] }}</span><span class="font-semibold text-base-content">{{ $order['total'] }}</span>
                    </div>
                </x-mary-card>
            </a>
        @empty
            <x-mary-alert title="Nenhuma ordem encontrada." icon="o-inbox" class="bg-base-200" />
        @endforelse
    </div>
    <x-operation.fab label="Nova OS" kind="service-order" />
</x-operation.page>
