<x-filament-panels::page class="bpo-workbench-page">
    @php
        $summary = $this->salesSummary();
        $money = fn ($value) => $value === null ? '—' : 'R$ '.number_format($value, 2, ',', '.');
    @endphp
    <section class="bpo-metrics" aria-label="Indicadores das vendas filtradas">
        <article><span class="bpo-metric-icon"><x-filament::icon icon="heroicon-o-banknotes" /></span><div><span>Valor faturado</span><strong class="is-money">{{ $money($summary['billed_amount']) }}</strong><small>{{ $summary['billed_amount'] === null ? 'Sem permissão para consultar faturas' : 'Líquido de descontos; exclui faturas canceladas' }}</small></div></article>
        <article><span class="bpo-metric-icon"><x-filament::icon icon="heroicon-o-document-check" /></span><div><span>Vendas faturadas</span><strong>{{ $summary['billed_count'] === null ? '—' : number_format($summary['billed_count'], 0, ',', '.') }}</strong><small>Vendas finalizadas com fatura válida</small></div></article>
        <article><span class="bpo-metric-icon"><x-filament::icon icon="heroicon-o-chart-bar" /></span><div><span>Ticket médio</span><strong class="is-money">{{ $money($summary['average_amount']) }}</strong><small>Valor faturado dividido pelas vendas faturadas</small></div></article>
        <article><span class="bpo-metric-icon"><x-filament::icon icon="heroicon-o-pencil-square" /></span><div><span>Em rascunho</span><strong>{{ number_format($summary['draft_count'], 0, ',', '.') }}</strong><small>{{ $money($summary['draft_amount']) }} em preparação</small></div></article>
    </section>
    <section class="bpo-workbench bpo-list-workbench" aria-label="Vendas e faturamento">
        <header class="bpo-workbench-header"><div><h2>Vendas e faturamento</h2><p>Cards e listagem seguem o mesmo período, filtros e busca, considerando todas as páginas.</p></div><span class="bpo-badge">{{ filament()->getTenant()->name }}</span></header>
        {{ $this->table }}
        <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>Período inicial: mês atual pela data da venda. O faturamento é comercial, sem depender da autorização fiscal, e não representa recebimento. Datas vazias consultam todo o histórico.</span></footer>
    </section>
</x-filament-panels::page>
