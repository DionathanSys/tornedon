<x-operation.page title="Produção e financeiro" subtitle="Indicadores de pedidos, contas e caixa.">
    <div class="operation-resource">
        <x-filament-widgets::widgets :widgets="$this->getVisibleWidgets()" :columns="['default' => 1, 'md' => 2]" :data="$this->getWidgetData()" />
    </div>
</x-operation.page>
