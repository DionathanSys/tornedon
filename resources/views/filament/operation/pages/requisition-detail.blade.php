<x-operation.page :title="$requisition ? $requisition['number'] : 'Detalhe da requisição'">
    @if ($requisition)
        <div class="grid grid-cols-2 gap-3">
            <x-mary-stat title="Total" :value="$requisition['total']" />
            <x-mary-stat title="Status" :value="$requisition['status']" />
        </div>
        <x-operation.panel title="Cliente e equipamento">
            <p class="font-semibold">{{ $requisition['customer_name'] }}</p>
            <p class="text-sm text-base-content/60">{{ $requisition['customer_doc'] }}</p>
            <p class="mt-3">{{ $requisition['equipment_name'] }} {{ $requisition['equipment_identifier'] }}</p>
            <p class="mt-1 text-sm text-base-content/60">OS: {{ $requisition['service_order_number'] }} · {{ $requisition['sale_date'] }}</p>
        </x-operation.panel>
        <x-operation.panel :title="'Itens ('.count($requisition['items']).')'">
            <div class="space-y-3">
                @forelse ($requisition['items'] as $item)
                    <div class="rounded-box bg-base-200 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="font-semibold">{{ $item['name'] }}</p>
                            <x-operation.status :value="$item['stock_consumed'] ? 'Estoque baixado' : 'Pendente'" :color="$item['stock_consumed'] ? 'success' : 'warning'" />
                        </div>
                        <p class="mt-2 text-sm text-base-content/60">{{ $item['quantity'] }} {{ $item['unit'] }} × {{ $item['unit_price'] }} = {{ $item['total'] }}</p>
                        <p class="mt-1 text-xs text-base-content/60">Código: {{ $item['code'] }}</p>
                    </div>
                @empty
                    <p class="text-sm text-base-content/60">Nenhum item adicionado.</p>
                @endforelse
            </div>
        </x-operation.panel>
        @if ($requisition['observations'])
            <x-operation.panel title="Observações"><p class="whitespace-pre-wrap">{{ $requisition['observations'] }}</p></x-operation.panel>
        @endif
    @else
        <x-mary-alert title="Requisição não encontrada." icon="o-exclamation-circle" class="alert-warning" />
    @endif
    {{ $this->getFooter() }}
</x-operation.page>
