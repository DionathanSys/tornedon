<x-filament-panels::page class="bpo-workbench-page">
    <section class="bpo-workbench bpo-list-workbench" aria-label="Documentos fiscais">
        <header class="bpo-workbench-header"><div><h2>Documentos fiscais</h2><p>Notas e retornos de emissão da empresa em operação.</p></div><span class="bpo-badge">{{ filament()->getTenant()->name }}</span></header>
        {{ $this->table }}
        <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>Confira os retornos em Detalhes e use Ações fiscais para visualizar, baixar e tratar documentos. As exportações abrangem notas de saída no período informado. A lista é atualizada a cada 15 segundos.</span></footer>
    </section>
</x-filament-panels::page>
