<div class="bpo-page" wire:poll.120s>
    @php($portfolio = $this->portfolio())
    <header class="bpo-page-heading"><div><span class="bpo-eyebrow">VISÃO DA CARTEIRA</span><h1>Seu trabalho começa por aqui.</h1><p>Acompanhe as empresas atendidas e escolha onde atuar agora.</p></div><button type="button" class="bpo-button is-secondary" wire:click="$refresh" wire:loading.attr="disabled"><x-filament::icon icon="heroicon-o-arrow-path" /><span wire:loading.remove>Atualizar carteira</span><span wire:loading>Atualizando…</span></button></header>
    <section class="bpo-metrics" aria-label="Resumo operacional">
        <article><span class="bpo-metric-icon"><x-filament::icon icon="heroicon-o-building-office-2" /></span><div><span>Empresas na carteira</span><strong>{{ $portfolio['total'] }}</strong><small>Atendimentos autorizados</small></div></article>
        <article><span class="bpo-metric-icon is-amber"><x-filament::icon icon="heroicon-o-clock" /></span><div><span>Precisam de atenção</span><strong>{{ $portfolio['attention'] }}</strong><small>Empresas com pendências visíveis</small></div></article>
        <article><span class="bpo-metric-icon"><x-filament::icon icon="heroicon-o-arrows-right-left" /></span><div><span>Para conciliar / revisar</span><strong>{{ $portfolio['reconciliation'] ?? '—' }}</strong><small>{{ $portfolio['reconciliation'] === null ? 'Módulo sem permissão de consulta' : 'Lançamentos bancários' }}</small></div></article>
        <article><span class="bpo-metric-icon is-rose"><x-filament::icon icon="heroicon-o-document-text" /></span><div><span>Pendências fiscais</span><strong>{{ $portfolio['fiscal'] ?? '—' }}</strong><small>{{ $portfolio['fiscal'] === null ? 'Módulo sem permissão de consulta' : 'Rejeições ou revisão de RPS' }}</small></div></article>
    </section>
    <section class="bpo-workbench">
        <header class="bpo-workbench-header"><div><h2>Empresas atendidas</h2><p>Apenas as empresas e os registros que você está autorizado a consultar.</p></div><span class="bpo-badge">{{ $portfolio['rows']->count() }} empresas exibidas</span></header>
        <div class="bpo-toolbar"><label class="bpo-search"><x-filament::icon icon="heroicon-o-magnifying-glass" /><span class="bpo-sr-only">Buscar empresa por nome ou CNPJ</span><input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar por nome ou CNPJ…" /></label><div class="bpo-segmented" role="group" aria-label="Filtrar carteira">@foreach (['all' => 'Toda a carteira', 'attention' => 'Com pendências', 'responsible' => 'Sou responsável'] as $key => $label)<button type="button" wire:click="$set('filter', '{{ $key }}')" class="{{ $filter === $key ? 'is-active' : '' }}" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}">{{ $label }}</button>@endforeach</div></div>
        <div class="bpo-table-scroll"><table class="bpo-portfolio-table"><thead><tr><th>Empresa / atendimento</th><th>A pagar em até 7 dias</th><th>Recebíveis vencidos</th><th>Conciliação</th><th>Fiscal</th><th><span class="bpo-sr-only">Acessar empresa</span></th></tr></thead><tbody>
            @forelse ($portfolio['rows'] as $row)
                <tr wire:key="portfolio-{{ $row['company']->id }}"><td><a class="bpo-company-title" href="{{ \App\Filament\Bpo\Pages\CompanyOverview::getUrl(panel: 'bpo', tenant: $row['company']) }}"><span class="bpo-company-avatar">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($row['company']->name, 0, 1)) }}</span><span><strong>{{ $row['company']->name }}</strong><small>{{ $row['company']->document_number ?: 'Documento não informado' }}</small><em>{{ $row['is_responsible'] ? 'Você é responsável' : 'Auxiliar · '.implode(', ', $row['responsibles']) }}</em></span></a></td>
                    @foreach (['payable_due', 'receivable_overdue', 'reconciliation_pending', 'fiscal_pending'] as $metric)
                        <td>@if ($row[$metric] === null)<span class="bpo-restricted" title="Seu papel não permite consultar este módulo">Sem acesso</span>@elseif ($row[$metric] > 0)<span class="bpo-number-badge {{ $metric === 'fiscal_pending' ? 'is-rose' : 'is-amber' }}">{{ $row[$metric] }}</span><small class="bpo-cell-caption">{{ $metric === 'payable_due' ? (($row['payable_overdue'] ?? 0).' já vencidos') : 'pendentes' }}</small>@else<span class="bpo-clear"><x-filament::icon icon="heroicon-o-check-circle" /> Em dia</span>@endif</td>
                    @endforeach
                    <td><a class="bpo-button is-small" href="{{ \App\Filament\Bpo\Pages\CompanyOverview::getUrl(panel: 'bpo', tenant: $row['company']) }}">Abrir empresa<x-filament::icon icon="heroicon-o-arrow-right" /></a></td>
                </tr>
            @empty
                <tr><td colspan="6"><div class="bpo-empty"><x-filament::icon icon="heroicon-o-magnifying-glass" /><strong>Nenhuma empresa neste filtro</strong><p>Tente outro nome ou selecione Toda a carteira.</p></div></td></tr>
            @endforelse
        </tbody></table></div>
        <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>Os indicadores respeitam o alcance dos seus papéis. Valores e registros de empresas diferentes permanecem separados.</span></footer>
    </section>
</div>
