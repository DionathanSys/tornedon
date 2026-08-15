<x-filament-panels::page>
    <style>
        .bs-reconcile-page { display: grid; gap: 1rem; }
        .bs-reconcile-hero { display: grid; gap: 1rem; padding: 1.1rem; border-radius: 1.4rem; color: #fff; background: linear-gradient(135deg, #0f172a, #1d4ed8 56%, #22c55e); box-shadow: 0 28px 70px -44px rgba(15, 23, 42, .9); }
        .bs-reconcile-hero__title { margin: 0; font-size: 1.2rem; font-weight: 900; letter-spacing: -.02em; }
        .bs-reconcile-hero__sub { margin: .35rem 0 0; font-size: .84rem; color: rgba(255, 255, 255, .82); }
        .bs-reconcile-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .65rem; }
        .bs-reconcile-kpi { padding: .85rem; border-radius: 1rem; background: rgba(255, 255, 255, .13); backdrop-filter: blur(10px); }
        .bs-reconcile-kpi span { display: block; font-size: .68rem; font-weight: 800; text-transform: uppercase; opacity: .75; }
        .bs-reconcile-kpi strong { display: block; margin-top: .25rem; font-size: 1rem; }
        .bs-reconcile-toolbar { display: grid; gap: .85rem; padding: .95rem; border: 1px solid rgba(148, 163, 184, .22); border-radius: 1.2rem; background: #fff; box-shadow: 0 18px 40px -38px rgba(15, 23, 42, .6); }
        .bs-reconcile-toolbar__filters { display: flex; flex-wrap: wrap; gap: .55rem; }
        .bs-reconcile-chip { border: 0; border-radius: 999px; padding: .62rem .9rem; background: #e2e8f0; color: #334155; font-size: .76rem; font-weight: 800; cursor: pointer; }
        .bs-reconcile-chip.is-active { background: #0f172a; color: #fff; }
        .bs-reconcile-search { width: 100%; min-height: 3rem; padding: 0 .95rem; border: 1px solid #cbd5e1; border-radius: .95rem; background: #f8fafc; color: #0f172a; }
        .bs-reconcile-list { display: grid; gap: .85rem; }
        .bs-reconcile-card { display: grid; gap: .9rem; padding: 1rem; border: 1px solid rgba(148, 163, 184, .2); border-radius: 1.25rem; background: #fff; box-shadow: 0 20px 50px -42px rgba(15, 23, 42, .55); }
        .bs-reconcile-card.is-reconciled { border-color: rgba(34, 197, 94, .35); background: linear-gradient(180deg, #ffffff, #f0fdf4); }
        .bs-reconcile-card.is-ignored { border-color: rgba(148, 163, 184, .28); background: linear-gradient(180deg, #ffffff, #f8fafc); }
        .bs-reconcile-card__top { display: flex; flex-wrap: wrap; gap: .65rem; align-items: start; justify-content: space-between; }
        .bs-reconcile-card__title { margin: 0; font-size: .98rem; font-weight: 850; color: #0f172a; }
        .bs-reconcile-card__meta { display: flex; flex-wrap: wrap; gap: .45rem; }
        .bs-reconcile-badge { display: inline-flex; align-items: center; justify-content: center; min-height: 2rem; padding: 0 .72rem; border-radius: 999px; font-size: .72rem; font-weight: 850; }
        .bs-reconcile-badge.is-inflow { background: #dcfce7; color: #166534; }
        .bs-reconcile-badge.is-outflow { background: #fee2e2; color: #991b1b; }
        .bs-reconcile-badge.is-pending { background: #fef3c7; color: #92400e; }
        .bs-reconcile-badge.is-reconciled { background: #dcfce7; color: #166534; }
        .bs-reconcile-badge.is-ignored { background: #e2e8f0; color: #475569; }
        .bs-reconcile-amount { font-size: 1.15rem; font-weight: 900; letter-spacing: -.02em; }
        .bs-reconcile-amount.is-inflow { color: #15803d; }
        .bs-reconcile-amount.is-outflow { color: #b91c1c; }
        .bs-reconcile-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .65rem; }
        .bs-reconcile-cell { padding: .72rem; border-radius: .95rem; background: #f8fafc; }
        .bs-reconcile-cell span { display: block; color: #64748b; font-size: .64rem; font-weight: 800; text-transform: uppercase; }
        .bs-reconcile-cell strong { display: block; margin-top: .22rem; color: #0f172a; font-size: .82rem; }
        .bs-reconcile-suggestion { padding: .85rem; border-radius: 1rem; background: linear-gradient(135deg, #eff6ff, #f8fafc); border: 1px solid rgba(59, 130, 246, .12); }
        .bs-reconcile-suggestion p { margin: 0; }
        .bs-reconcile-suggestion__label { font-size: .86rem; font-weight: 800; color: #0f172a; }
        .bs-reconcile-suggestion__reason { margin-top: .28rem; color: #475569; font-size: .76rem; }
        .bs-reconcile-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .bs-reconcile-action { border: 0; border-radius: .9rem; padding: .72rem .92rem; color: #fff; font-size: .76rem; font-weight: 850; cursor: pointer; }
        .bs-reconcile-action--primary { background: #0f172a; }
        .bs-reconcile-action--info { background: #0369a1; }
        .bs-reconcile-action--success { background: #15803d; }
        .bs-reconcile-action--warning { background: #b45309; }
        .bs-reconcile-action--gray { background: #475569; }
        .bs-reconcile-action--danger { background: #b91c1c; }
        .bs-reconcile-empty { padding: 1.35rem; border-radius: 1.2rem; text-align: center; color: #64748b; background: #fff; border: 1px dashed #cbd5e1; }
        @media (max-width: 900px) {
            .bs-reconcile-kpis,
            .bs-reconcile-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .bs-reconcile-kpis,
            .bs-reconcile-grid { grid-template-columns: 1fr; }
            .bs-reconcile-card__top { flex-direction: column; }
            .bs-reconcile-actions { display: grid; grid-template-columns: 1fr; }
        }
    </style>

    <div class="bs-reconcile-page">
        <section class="bs-reconcile-hero">
            <div>
                <p class="bs-reconcile-hero__title">Linhas do extrato prontas para decisão</p>
                <p class="bs-reconcile-hero__sub">Concilie pela melhor sugestão, abra as ações avançadas ou marque exceções sem sair da mesma tela.</p>
            </div>

            <div class="bs-reconcile-kpis">
                <div class="bs-reconcile-kpi"><span>Total</span><strong>{{ $this->statusCounts['all'] }}</strong></div>
                <div class="bs-reconcile-kpi"><span>Pendentes</span><strong>{{ $this->statusCounts['pending'] }}</strong></div>
                <div class="bs-reconcile-kpi"><span>Conciliadas</span><strong>{{ $this->statusCounts['reconciled'] }}</strong></div>
                <div class="bs-reconcile-kpi"><span>Ignoradas</span><strong>{{ $this->statusCounts['ignored'] }}</strong></div>
            </div>
        </section>

        <section class="bs-reconcile-toolbar">
            <div class="bs-reconcile-toolbar__filters">
                @foreach (['pending' => 'Pendentes', 'reconciled' => 'Conciliadas', 'ignored' => 'Ignoradas', 'all' => 'Todas'] as $filter => $label)
                    <button
                        type="button"
                        class="bs-reconcile-chip {{ $this->statusFilter === $filter ? 'is-active' : '' }}"
                        wire:click="setStatusFilter('{{ $filter }}')"
                    >
                        {{ $label }} ({{ $this->statusCounts[$filter] }})
                    </button>
                @endforeach
            </div>

            <input
                type="text"
                class="bs-reconcile-search"
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por descrição, documento, sugestão ou movimento vinculado"
            />
        </section>

        <section class="bs-reconcile-list">
            @forelse ($this->filteredLines as $line)
                @php
                    $direction = $line->direction();
                    $status = $line->reconciliation_status?->value ?? 'pending';
                    $suggestion = $line->suggestions()[0] ?? null;
                @endphp

                <article class="bs-reconcile-card {{ $status === 'reconciled' ? 'is-reconciled' : '' }} {{ $status === 'ignored' ? 'is-ignored' : '' }}">
                    <div class="bs-reconcile-card__top">
                        <div>
                            <p class="bs-reconcile-card__title">{{ $line->description ?: 'Sem descrição' }}</p>
                            <div class="bs-reconcile-card__meta">
                                <span class="bs-reconcile-badge {{ $direction?->value === 'outflow' ? 'is-outflow' : 'is-inflow' }}">{{ $direction?->description() ?? 'Sem tipo' }}</span>
                                <span class="bs-reconcile-badge is-{{ $status }}">{{ $line->reconciliation_status?->description() ?? 'Pendente' }}</span>
                                @if ($line->document_number)
                                    <span class="bs-reconcile-badge is-ignored">Doc {{ $line->document_number }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="bs-reconcile-amount {{ $direction?->value === 'outflow' ? 'is-outflow' : 'is-inflow' }}">
                            {{ $direction?->value === 'outflow' ? '-' : '+' }} R$ {{ number_format((float) $line->amount, 2, ',', '.') }}
                        </div>
                    </div>

                    <div class="bs-reconcile-grid">
                        <div class="bs-reconcile-cell">
                            <span>Data</span>
                            <strong>{{ $line->transaction_date?->format('d/m/Y') ?? '-' }}</strong>
                        </div>
                        <div class="bs-reconcile-cell">
                            <span>Saldo</span>
                            <strong>R$ {{ number_format((float) $line->balance_amount, 2, ',', '.') }}</strong>
                        </div>
                        <div class="bs-reconcile-cell">
                            <span>Movimento vinculado</span>
                            <strong>{{ $line->cashMovement?->description ?? '-' }}</strong>
                        </div>
                        <div class="bs-reconcile-cell">
                            <span>Linha</span>
                            <strong>#{{ $line->id }}</strong>
                        </div>
                    </div>

                    @if (is_array($suggestion))
                        <div class="bs-reconcile-suggestion">
                            <p class="bs-reconcile-suggestion__label">Melhor sugestão: {{ $suggestion['label'] ?? 'Sugestão disponível' }}</p>
                            <p class="bs-reconcile-suggestion__reason">{{ $suggestion['reason'] ?? 'Sem justificativa adicional.' }} @if(isset($suggestion['score'])) • score {{ $suggestion['score'] }} @endif</p>
                        </div>
                    @endif

                    @if ($status !== 'reconciled')
                        <div class="bs-reconcile-actions">
                            @if (is_array($suggestion))
                                <button type="button" class="bs-reconcile-action bs-reconcile-action--primary" wire:click="reconcileSuggestion({{ $line->id }})">Conciliar sugestão</button>
                            @endif

                            <button type="button" class="bs-reconcile-action bs-reconcile-action--info" wire:click="openLineAction('reconcileMovement', {{ $line->id }})">Vincular movimento</button>

                            @if ($direction?->value === 'outflow')
                                <button type="button" class="bs-reconcile-action bs-reconcile-action--warning" wire:click="openLineAction('reconcilePayableInstallment', {{ $line->id }})">Baixar conta a pagar</button>
                            @elseif ($direction?->value === 'inflow')
                                <button type="button" class="bs-reconcile-action bs-reconcile-action--success" wire:click="openLineAction('reconcileReceivableInstallment', {{ $line->id }})">Baixar conta a receber</button>
                            @endif

                            <button type="button" class="bs-reconcile-action bs-reconcile-action--gray" wire:click="openLineAction('createManualMovement', {{ $line->id }})">Criar movimento</button>
                            <button type="button" class="bs-reconcile-action bs-reconcile-action--danger" wire:click="openLineAction('ignoreStatementLine', {{ $line->id }})">Ignorar</button>
                            <button type="button" class="bs-reconcile-action bs-reconcile-action--gray" wire:click="refreshSuggestions({{ $line->id }})">Atualizar sugestões</button>
                        </div>
                    @endif
                </article>
            @empty
                <div class="bs-reconcile-empty">Nenhuma linha encontrada para o filtro atual.</div>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
