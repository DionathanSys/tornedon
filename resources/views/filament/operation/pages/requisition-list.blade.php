<x-operation.page title="Requisições">
    <x-operation.panel>
        <x-mary-input wire:model.live.debounce.300ms="search" placeholder="Buscar número, cliente ou OS" icon="o-magnifying-glass" clearable aria-label="Buscar requisições" />
        @include('filament.operation.date-filters')
        <x-operation.list-tabs :active="$activeTab" :open="$openCount" :closed="$closedCount" :all="$allCount" />
    </x-operation.panel>
    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100 [&_hr]:border-base-300">
        @forelse ($requisitions as $req)
            <x-operation.record-item :record="$req" :title="$req['number']" :subtitle="$req['customer']" :status="$req['status']" :color="$req['status_value']" :amount="$req['total']" :details="['OS: '.$req['service_order'], $req['items_count'].' itens · '.$req['sale_date']]" icon="o-clipboard-document" />
        @empty
            <x-mary-alert title="Nenhuma requisição encontrada." icon="o-inbox" class="bg-base-200" />
        @endforelse
    </div>
    <x-operation.pagination :paginator="$pagination" />
    <x-operation.fab label="Nova requisição" kind="requisition" />
</x-operation.page>
