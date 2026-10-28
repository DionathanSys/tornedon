<x-filament-panels::page class="bpo-workbench-page">
    <div class="bpo-form-grid">
        <div class="bpo-form-content">{{ $this->content }}</div>
        <aside class="bpo-workbench bpo-form-guide" aria-label="Orientações do lançamento">
            <header class="bpo-workbench-header"><div><span class="bpo-eyebrow">LANÇAMENTO FINANCEIRO</span><h2>Confira antes de salvar</h2></div><x-filament::icon icon="heroicon-o-clipboard-document-check" /></header>
            <dl class="bpo-account-fields">
                <div><dt>Empresa em operação</dt><dd>{{ filament()->getTenant()->name }}</dd></div>
                <div><dt>Dados do lançamento</dt><dd>Confira a contraparte, a descrição e os valores informados.</dd></div>
                <div><dt>Classificação financeira</dt><dd>Revise a conta, a categoria e os centros de custo e resultado disponíveis no formulário.</dd></div>
            </dl>
            <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>Os campos obrigatórios estão sinalizados no formulário.</span></footer>
        </aside>
    </div>
</x-filament-panels::page>
