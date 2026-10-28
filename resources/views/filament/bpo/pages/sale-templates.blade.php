<x-filament-panels::page class="bpo-workbench-page">
    <section class="bpo-workbench bpo-list-workbench" aria-label="Modelos de venda">
        <header class="bpo-workbench-header"><div><h2>Modelos de venda</h2><p>Padrões por empresa para preparar serviços e produtos.</p></div><span class="bpo-badge">{{ filament()->getTenant()->name }}</span></header>
        {{ $this->table }}
        <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>As vendas guardam a configuração do modelo utilizado. Alterar um modelo não altera os rascunhos já iniciados.</span></footer>
    </section>
</x-filament-panels::page>
