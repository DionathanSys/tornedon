<div class="bpo-page bpo-cash-flow" wire:poll.120s>
    @php
        $report = $this->report();
        $options = $report['companies'] ?? app(\App\Services\Bpo\BpoAccessService::class)->companies(\Filament\Facades\Filament::auth()->user());
        $reference = \Carbon\CarbonImmutable::parse($report ? $startDate : 'today')->subDay();
    @endphp
    <header class="bpo-page-heading">
        <div><span class="bpo-eyebrow">INTELIGÊNCIA FINANCEIRA · CARTEIRA</span><h1>Fluxo de caixa e previsão</h1><p>Saiba o que entra, o que sai e quando cada empresa precisa de caixa.</p></div>
        <button type="button" class="bpo-button is-secondary" wire:click="$refresh" wire:loading.attr="disabled"><x-filament::icon icon="heroicon-o-arrow-path" />Atualizar análise</button>
    </header>

    <section class="bpo-workbench bpo-flow-filters" aria-label="Filtros da análise">
        <header class="bpo-workbench-header"><div><h2>Seu recorte financeiro</h2><p>Selecione uma empresa ou consolide a carteira.</p></div><span class="bpo-badge">{{ count($companyIds) }} selecionadas</span></header>
        <div class="bpo-flow-filter-body">
            <div class="bpo-flow-company-filter">
                <button type="button" class="bpo-button is-secondary" wire:click="openCompanies" wire:loading.attr="disabled" wire:target="openCompanies" aria-haspopup="dialog" aria-controls="cash-flow-companies"><x-filament::icon icon="heroicon-o-building-office-2" /><span>{{ count($companyIds) === 1 ? $options->firstWhere('id', $companyIds[0])?->name : (count($companyIds).' empresas selecionadas') }}</span><x-filament::icon icon="heroicon-o-adjustments-horizontal" /></button>
            </div>
            <div class="bpo-flow-month-filter">
                <x-bpo.month-selector :month="$month" :active="$period === 'month'" />
            </div>
            <div class="bpo-flow-range-filter">{{ $this->form }}</div>
            <div class="bpo-segmented bpo-flow-periods" role="group" aria-label="Períodos rápidos">
                @foreach (['today' => 'Hoje', 'tomorrow' => 'Amanhã', 'this_week' => 'Nesta semana', 'next_week' => 'Semana que vem', 'next_7_days' => '7 dias', 'next_30_days' => '30 dias'] as $key => $label)
                    <button type="button" wire:click="setPeriod('{{ $key }}')" class="{{ $period === $key ? 'is-active' : '' }}" aria-pressed="{{ $period === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        @if ($this->getErrorBag()->any())<div class="bpo-flow-notice is-danger" role="alert">{{ $this->getErrorBag()->first() }}</div>@endif
    </section>

    <x-bpo.company-selection-modal :companies="$options" />

    @if ($report && count($companyIds))
        @if ($report['partial'])<div class="bpo-flow-notice" role="status"><x-filament::icon icon="heroicon-o-information-circle" /><span><strong>Análise parcial.</strong> Os valores incluem apenas contas e registros permitidos pelos seus papéis. A suficiência de caixa não representa a totalidade das empresas.</span></div>@endif
        <section class="bpo-metrics" aria-label="Resumo financeiro do período">
            @foreach ([['Saldo de abertura', $report['opening'], 'Fechamento de '.$reference->format('d/m/Y').($reference->gte(today()) ? ' · previsto' : ' · histórico'), 'heroicon-o-building-library'], ['A receber no período', $report['in'], 'Realizado + previsto, conforme a data', 'heroicon-o-arrow-down-left'], ['A pagar no período', $report['out'], 'Inclui vencidos projetados para hoje', 'heroicon-o-arrow-up-right'], ['Saldo ao final', $report['closing'], \Carbon\CarbonImmutable::parse($endDate)->format('d/m/Y').' · '.($endDate < today()->toDateString() ? 'realizado' : 'previsto'), 'heroicon-o-chart-bar']] as [$label, $value, $caption, $icon])
                <article><span class="bpo-metric-icon {{ $value < 0 ? 'is-rose' : '' }}"><x-filament::icon :icon="$icon" /></span><div><span>{{ $label }}</span><strong class="{{ $value < 0 ? 'bpo-flow-negative' : '' }}">{{ $this->money($value) }}</strong><small>{{ $caption }}</small></div></article>
            @endforeach
        </section>
        <div class="bpo-flow-insights">
            <div class="bpo-flow-notice {{ $report['first_shortage'] ? 'is-danger' : '' }}"><x-filament::icon :icon="$report['first_shortage'] ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'" /><span>@if ($shortage = $report['first_shortage'])<strong>Primeiro fechamento negativo: {{ \Carbon\CarbonImmutable::parse($shortage['date'])->format('d/m/Y') }}.</strong> Faltam {{ $this->money(abs($shortage['balance'])) }} nesse dia.@else<strong>Sem fechamento negativo no consolidado deste período.</strong> Confira a cobertura individual das empresas abaixo.@endif</span></div>
            <div class="bpo-flow-notice"><x-filament::icon icon="heroicon-o-clock" /><span><strong>{{ $this->money($report['overdue_receivables']) }} em recebíveis vencidos.</strong> Exibidos à parte, sem entrada presumida na previsão.</span></div>
        </div>

        <section class="bpo-workbench">
            <header class="bpo-workbench-header"><div><h2>Evolução do saldo diário</h2><p>Fechamento da véspera + entradas − saídas. Passe sobre os pontos para consultar os valores.</p></div><span class="bpo-badge">{{ count($report['days']) }} dias</span></header>
            @php
                $days = array_values($report['days']);
                $values = array_merge([0, $report['opening']], array_column($days, 'balance'));
                $min = min($values); $max = max($values); $spread = max(100, $max - $min);
                $min -= $spread * .12; $max += $spread * .12;
                $width = max(800, count($days) * 65 + 130); $height = 280;
                $y = fn ($value) => round(25 + ($max - $value) / ($max - $min) * 205, 2);
                $x = fn ($i) => round(115 + $i * ($width - 155) / max(1, count($days) - 1), 2);
                $points = collect($days)->map(fn ($day, $i) => $x($i).','.$y($day['balance']))->implode(' ');
            @endphp
            <div class="bpo-table-scroll bpo-flow-chart">
                <svg viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" role="img" aria-labelledby="flow-chart-title flow-chart-description">
                    <title id="flow-chart-title">Saldo diário das empresas selecionadas</title><desc id="flow-chart-description">Valores em reais. Dias passados são realizados; de hoje em diante são previstos. O detalhamento acessível está na tabela diária abaixo.</desc>
                    @foreach ([$max, ($max + $min) / 2, $min] as $tick)
                        <line x1="105" x2="{{ $width - 20 }}" y1="{{ $y($tick) }}" y2="{{ $y($tick) }}" class="bpo-flow-grid" /><text x="95" y="{{ $y($tick) + 4 }}" text-anchor="end">{{ $this->money($tick) }}</text>
                    @endforeach
                    <line x1="105" x2="{{ $width - 20 }}" y1="{{ $y(0) }}" y2="{{ $y(0) }}" class="bpo-flow-zero" />
                    <polyline points="{{ $points }}" class="bpo-flow-line" />
                    @foreach ($days as $i => $day)
                        <circle cx="{{ $x($i) }}" cy="{{ $y($day['balance']) }}" r="5" class="{{ $day['balance'] < 0 ? 'bpo-flow-point-negative' : 'bpo-flow-point' }}"><title>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d/m/Y') }} · Abertura: {{ $this->money($day['opening']) }} · Entradas: {{ $this->money($day['in']) }} · Saídas: {{ $this->money($day['out']) }} · Fechamento: {{ $this->money($day['balance']) }}</title></circle>
                        <text x="{{ $x($i) }}" y="257" text-anchor="middle">{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d/m') }}</text>
                    @endforeach
                </svg>
            </div>
            <footer class="bpo-workbench-footer"><x-filament::icon icon="heroicon-o-information-circle" /><span>Histórico do Tornedon, conforme os registros atuais. Não é uma consulta ao banco. Datas futuras incluem compromissos intermediários até o início do período. Saldos iniciais de contas abertas no período entram na respectiva data. Transferências entre contas visíveis da mesma empresa não compõem entradas e saídas.</span></footer>
        </section>

        <section class="bpo-workbench">
            <header class="bpo-workbench-header"><div><h2>Caixa por empresa</h2><p>Um consolidado positivo não cobre automaticamente o déficit de outra empresa.</p></div></header>
            <div class="bpo-table-scroll"><table class="bpo-portfolio-table"><thead><tr><th>Empresa</th><th>Abertura</th><th>Entradas</th><th>Saídas</th><th>Fechamento</th><th>Cobertura no período</th></tr></thead><tbody>
                @foreach ($report['rows'] as $row)
                    <tr wire:key="flow-row-{{ $row['company']->id }}"><td><strong>{{ $row['company']->name }}</strong>@if ($row['partial'])<small class="bpo-cell-caption">Visão parcial</small>@endif</td><td>{{ $this->money($row['opening']) }}</td><td>{{ $this->money($row['in']) }}</td><td>{{ $this->money($row['out']) }}</td><td class="{{ $row['closing'] < 0 ? 'bpo-flow-negative' : '' }}">{{ $this->money($row['closing']) }}</td><td>@if ($row['first_shortage'])<span class="bpo-number-badge is-rose">Falta em {{ \Carbon\CarbonImmutable::parse($row['first_shortage']['date'])->format('d/m') }}</span><small class="bpo-cell-caption">{{ $this->money(abs($row['first_shortage']['balance'])) }} no fechamento</small>@elseif ($row['without_receipts'] < 0)<span class="bpo-number-badge is-amber">Depende de entradas</span>@else<span class="bpo-clear">Saldo de abertura cobre as saídas</span>@endif</td></tr>
                @endforeach
            </tbody></table></div>
        </section>

        <section class="bpo-workbench">
            <header class="bpo-workbench-header"><div><h2>Detalhamento diário</h2><p>Realizado para dias passados; realizado + compromissos em aberto para hoje e datas futuras.</p></div></header>
            <div class="bpo-flow-daily-scroll"><table class="bpo-portfolio-table"><thead><tr><th>Dia</th><th>Visão</th><th>Abertura</th><th>Entradas</th><th>Saídas</th><th>Saldos iniciais de contas</th><th>Fechamento</th></tr></thead><tbody>
                @foreach ($days as $day)<tr><td>{{ \Carbon\CarbonImmutable::parse($day['date'])->format('d/m/Y') }}</td><td>{{ $day['date'] < today()->toDateString() ? 'Realizado' : 'Previsto' }}</td><td>{{ $this->money($day['opening']) }}</td><td>{{ $this->money($day['in']) }}</td><td>{{ $this->money($day['out']) }}</td><td>{{ $this->money($day['adjustment']) }}</td><td class="{{ $day['balance'] < 0 ? 'bpo-flow-negative' : '' }}">{{ $this->money($day['balance']) }}</td></tr>@endforeach
            </tbody></table></div>
        </section>

        @foreach (['payable' => 'Contas a pagar em aberto', 'receivable' => 'Contas a receber em aberto'] as $type => $label)
            <section class="bpo-workbench"><header class="bpo-workbench-header"><div><h2>{{ $label }}</h2><p>{{ $type === 'payable' ? 'Vencidas alocadas em hoje; demais parcelas pelo vencimento.' : 'Recebíveis vencidos identificados à parte e excluídos da previsão.' }}</p></div></header>
                <div class="bpo-flow-daily-scroll"><table class="bpo-portfolio-table"><thead><tr><th>Empresa</th><th>Favorecido / cliente</th><th>Descrição</th><th>Vencimento</th><th>Saldo em aberto</th><th></th></tr></thead><tbody>
                    @forelse ($report['titles']->where('type', $type) as $title)
                        <tr><td>{{ $title['company']->name }}</td><td>{{ $title['party'] }}</td><td>{{ $title['description'] ?: '—' }}</td><td>{{ \Carbon\CarbonImmutable::parse($title['date'])->format('d/m/Y') }}@if ($title['overdue'])<small class="bpo-cell-caption">{{ $type === 'receivable' ? 'Vencido · fora da previsão' : 'Vencido · previsto para hoje' }}</small>@endif</td><td>{{ $this->money($title['amount']) }}</td><td><a class="bpo-button is-small is-secondary" href="{{ ($type === 'payable' ? \App\Filament\Bpo\Resources\AccountPayableResource::class : \App\Filament\Bpo\Resources\AccountReceivableResource::class)::getUrl('view', ['record' => $title['record']], panel: 'bpo', tenant: $title['company']) }}">Ver conta</a></td></tr>
                    @empty<tr><td colspan="6"><div class="bpo-empty">Nenhuma parcela em aberto neste recorte.</div></td></tr>@endforelse
                </tbody></table></div>
            </section>
        @endforeach
        <section class="bpo-workbench"><header class="bpo-workbench-header"><div><h2>Saldos por conta</h2><p>Saldo inicial + movimentos registrados até {{ $reference->format('d/m/Y') }}. Compromissos em aberto entram na projeção da empresa.</p></div></header><div class="bpo-table-scroll"><table class="bpo-portfolio-table"><thead><tr><th>Empresa</th><th>Conta financeira</th><th>Saldo registrado na referência</th></tr></thead><tbody>@forelse ($report['accounts'] as $account)<tr><td>{{ $account['company']->name }}</td><td>{{ $account['name'] }}</td><td>{{ $this->money($account['balance']) }}</td></tr>@empty<tr><td colspan="3"><div class="bpo-empty">Nenhuma conta financeira disponível nas suas permissões.</div></td></tr>@endforelse</tbody></table></div></section>
    @elseif ($report)
        <section class="bpo-workbench"><div class="bpo-empty"><x-filament::icon icon="heroicon-o-building-office-2" /><strong>Selecione uma ou mais empresas</strong><p>Use o filtro para iniciar a análise financeira.</p></div></section>
    @endif
</div>
