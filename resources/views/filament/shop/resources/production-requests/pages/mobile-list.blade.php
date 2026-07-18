<x-filament-panels::page>
    <style>
        .pr-mob-page { display: grid; gap: 1rem; padding-bottom: 1rem; }
        .pr-mob-top-actions { display: grid; grid-template-columns: 1.2fr 1fr; gap: .5rem; }
        .pr-mob-new, .pr-mob-report { display: inline-flex; align-items: center; justify-content: center; min-height: 3.4rem; border-radius: 1rem; font-weight: 800; text-align: center; text-decoration: none; }
        .pr-mob-new { background: #111827; color: #fff; }
        .pr-mob-report { background: #e2e8f0; color: #334155; }
        .pr-mob-tabs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; }
        .pr-mob-tab { min-height: 3.3rem; border: 0; border-radius: .9rem; background: #e2e8f0; color: #334155; font-size: .75rem; font-weight: 800; }
        .pr-mob-tab span { display: block; margin-top: .2rem; font-size: .7rem; opacity: .8; }
        .pr-mob-tab.is-active { background: #111827; color: #fff; }
        .pr-mob-list { display: grid; gap: .75rem; }
        .pr-mob-card { display: grid; gap: .75rem; border: 1px solid rgba(148, 163, 184, .25); border-radius: 1rem; padding: .95rem; background: #fff; color: #0f172a; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-card__top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .pr-mob-card__title { margin: 0; font-size: .98rem; font-weight: 800; }
        .pr-mob-card__sub { margin: .25rem 0 0; color: #64748b; font-size: .76rem; }
        .pr-mob-badge { white-space: nowrap; border-radius: 999px; padding: .25rem .55rem; background: #fef3c7; color: #92400e; font-size: .7rem; font-weight: 800; }
        .pr-mob-badge--success { background: #dcfce7; color: #166534; }
        .pr-mob-badge--danger { background: #fee2e2; color: #b91c1c; }
        .pr-mob-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .pr-mob-meta div { border-radius: .75rem; padding: .55rem .65rem; background: #f8fafc; }
        .pr-mob-meta span { display: block; color: #64748b; font-size: .65rem; font-weight: 800; text-transform: uppercase; }
        .pr-mob-meta strong { display: block; margin-top: .2rem; font-size: .82rem; }
        .pr-mob-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
        .pr-mob-actions a, .pr-mob-actions button { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; border: 0; border-radius: .8rem; font-size: .76rem; font-weight: 850; text-decoration: none; }
        .pr-mob-open { background: #111827; color: #fff; }
        .pr-mob-delete { background: #fee2e2; color: #b91c1c; }
        .pr-mob-delete:disabled { opacity: .55; }
        .pr-mob-empty { border-radius: 1rem; padding: 1rem; background: #fff; color: #64748b; text-align: center; }
    </style>

    <div class="pr-mob-page">
        <div class="pr-mob-top-actions">
            <a href="{{ $this->getCreateUrl() }}" class="pr-mob-new">Novo pedido</a>
            <a href="{{ $this->getOpenProductsReportUrl() }}" class="pr-mob-report">Resumo</a>
        </div>

        <div class="pr-mob-tabs">
            <button type="button" wire:click="setTab('open')" class="pr-mob-tab @if ($activeTab === 'open') is-active @endif">Abertos<span>{{ $this->openCount }}</span></button>
            <button type="button" wire:click="setTab('delivered')" class="pr-mob-tab @if ($activeTab === 'delivered') is-active @endif">Entregues<span>{{ $this->deliveredCount }}</span></button>
            <button type="button" wire:click="setTab('all')" class="pr-mob-tab @if ($activeTab === 'all') is-active @endif">Todos<span>{{ $this->allCount }}</span></button>
        </div>

        <div class="pr-mob-list">
            @forelse ($this->productionRequests as $request)
                <div class="pr-mob-card">
                    <div class="pr-mob-card__top">
                        <div>
                            <p class="pr-mob-card__title">{{ $request->counterparty_label }}</p>
                            <p class="pr-mob-card__sub">{{ $request->status->description() }} - {{ $request->order_date?->format('d/m/Y') ?? '-' }}</p>
                        </div>

                        <span class="pr-mob-badge @if ($request->status === \App\Enum\ProductionRequest\Status::DELIVERED) pr-mob-badge--success @elseif ($request->status === \App\Enum\ProductionRequest\Status::CANCELLED) pr-mob-badge--danger @endif">{{ $request->status->description() }}</span>
                    </div>

                    <div class="pr-mob-meta">
                        <div><span>Total</span><strong>R$ {{ number_format((float) $request->total_amount, 2, ',', '.') }}</strong></div>
                        <div><span>Itens</span><strong>{{ $request->items->count() }}</strong></div>
                    </div>

                    <div class="pr-mob-actions">
                        <a href="{{ $this->getDetailUrl($request) }}" class="pr-mob-open">Abrir</a>
                        <button
                            type="button"
                            class="pr-mob-delete"
                            wire:click="deleteProductionRequest({{ $request->id }})"
                            wire:confirm="Tem certeza que deseja excluir este pedido?"
                            @disabled($request->status !== \App\Enum\ProductionRequest\Status::OPEN)
                        >Excluir</button>
                    </div>
                </div>
            @empty
                <div class="pr-mob-empty">Nenhum pedido encontrado.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
