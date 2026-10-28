<x-filament-panels::page class="bpo-workbench-page">
    <section class="bpo-workbench bpo-list-workbench" aria-label="Vendas e faturamento">
        <header class="bpo-workbench-header"><div><h2>Vendas e faturamento</h2><p>Rascunhos, finalização comercial e emissão fiscal no fluxo de OS e requisições.</p></div><span class="bpo-badge">{{ filament()->getTenant()->name }}</span></header>
        {{ $this->table }}
        <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>Salvar rascunho não gera fatura ou recebível. Finalizar confirma a venda; autorização fiscal não registra recebimento.</span></footer>
    </section>
</x-filament-panels::page>
