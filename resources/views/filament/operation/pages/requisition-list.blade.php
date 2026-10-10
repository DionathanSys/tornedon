<x-operation.page title="Requisições">
    <x-mary-input wire:model.live.debounce.300ms="search" placeholder="Buscar número, cliente ou OS" icon="o-magnifying-glass" clearable aria-label="Buscar requisições" />
    <x-operation.list-tabs :active="$activeTab" :open="$openCount" :closed="$closedCount" :all="$allCount" />
    <div class="space-y-3">
        @forelse ($requisitions as $req)
            <a href="{{ $req['url'] }}" wire:navigate class="block" wire:key="requisition-{{ $req['number'] }}">
                <x-mary-card shadow>
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-bold">{{ $req['number'] }}</span>
                        <x-operation.status :value="$req['status']" :color="$req['status_value']" />
                    </div>
                    <p class="mt-2 font-medium">{{ $req['customer'] }}</p>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-sm text-base-content/60">
                        <span>OS: {{ $req['service_order'] }}</span><span>{{ $req['items_count'] }} itens</span>
                        <span>{{ $req['sale_date'] }}</span><span class="font-semibold text-base-content">{{ $req['total'] }}</span>
                    </div>
                </x-mary-card>
            </a>
        @empty
            <x-mary-alert title="Nenhuma requisição encontrada." icon="o-inbox" class="bg-base-200" />
        @endforelse
    </div>
    <x-operation.fab label="Nova requisição" kind="requisition" />
</x-operation.page>
