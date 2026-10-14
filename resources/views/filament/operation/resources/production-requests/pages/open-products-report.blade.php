<x-operation.page title="Produtos em aberto" subtitle="Produtos agrupados pelas quantidades pendentes.">
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
        <x-operation.stat title="Pedidos" :value="$this->openOrdersCount" />
        <x-operation.stat title="Produtos" :value="$this->productsCount" />
        <x-operation.stat title="Quantidade" :value="number_format((float) $this->totalQuantity, 3, ',', '.')" />
    </div>
    <x-mary-button label="Voltar para pedidos" icon="o-arrow-left" :link="$this->getListUrl()" class="btn-outline" />
    <x-operation.panel title="Produtos">
        <div class="space-y-3">
            @forelse ($this->rows as $row)
                <div class="rounded-box bg-base-200 p-4">
                    <p class="font-semibold">{{ $row->product_name }}</p>
                    <p class="mt-1 text-sm text-base-content/60">{{ number_format((float) $row->total_quantity, 3, ',', '.') }} {{ $row->unit_of_measure }} · {{ $row->orders_count }} pedido(s)</p>
                </div>
            @empty
                <x-mary-alert title="Nenhum produto em pedidos abertos." icon="o-inbox" class="bg-base-200" />
            @endforelse
        </div>
    </x-operation.panel>
</x-operation.page>
