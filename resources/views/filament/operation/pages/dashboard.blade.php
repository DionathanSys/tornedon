<x-filament-panels::page>
    <style>
        .op-dash { display: grid; gap: 1rem; }
        .op-section-title { font-size: 0.95rem; font-weight: 800; color: #0f172a; margin: 0.5rem 0 0; }
        .op-kpi-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.6rem; }
        .op-kpi { border: 1px solid rgba(228,228,231,0.6); border-radius: 1rem; padding: 0.85rem; background: #fff; }
        .op-kpi__label { color: #64748b; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.02em; }
        .op-kpi__value { margin-top: 0.3rem; font-size: 1.4rem; font-weight: 800; color: #0f172a; }
        .op-kpi__value--green { color: #16a34a; }
        .op-quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.6rem; }
        .op-quick { display: flex; align-items: center; gap: 0.75rem; border: 1px solid rgba(228,228,231,0.6); border-radius: 1rem; padding: 0.85rem; background: #fff; text-decoration: none; color: #0f172a; transition: background 0.15s; }
        .op-quick:active { background: #f4f4f5; }
        .op-quick__icon { display: flex; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: 0.85rem; background: #f1f5f9; flex-shrink: 0; }
        .op-quick__icon svg { width: 1.15rem; height: 1.15rem; color: #475569; }
        .op-quick__text { font-size: 0.78rem; font-weight: 700; }
        .op-quick__sub { font-size: 0.68rem; color: #64748b; font-weight: 600; }
        .op-recent { display: grid; gap: 0.6rem; }
        .op-card { border: 1px solid rgba(228,228,231,0.6); border-radius: 1rem; padding: 0.85rem; background: #fff; text-decoration: none; color: #0f172a; display: block; transition: background 0.15s; }
        .op-card:active { background: #f4f4f5; }
        .op-card__top { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .op-card__number { font-size: 0.88rem; font-weight: 800; }
        .op-card__badge { white-space: nowrap; border-radius: 999px; padding: 0.2rem 0.5rem; font-size: 0.65rem; font-weight: 700; }
        .op-card__badge--info { background: #dbeafe; color: #1d4ed8; }
        .op-card__badge--success { background: #dcfce7; color: #166534; }
        .op-card__badge--warning { background: #fef3c7; color: #92400e; }
        .op-card__badge--danger { background: #fee2e2; color: #b91c1c; }
        .op-card__badge--gray { background: #e5e7eb; color: #374151; }
        .op-card__sub { margin-top: 0.2rem; color: #64748b; font-size: 0.72rem; }
        .op-empty { border-radius: 1rem; padding: 1rem; background: #fff; color: #64748b; text-align: center; font-size: 0.8rem; }
    </style>

    <div class="op-dash">
        <section>
            <p class="op-section-title">Hoje</p>
            <div class="op-kpi-grid" style="margin-top: 0.5rem;">
                <div class="op-kpi">
                    <p class="op-kpi__label">OS no dia</p>
                    <p class="op-kpi__value">{{ $todayStats['total'] ?? 0 }}</p>
                </div>
                <div class="op-kpi">
                    <p class="op-kpi__label">Abertas</p>
                    <p class="op-kpi__value" style="color: #2563eb;">{{ $todayStats['open'] ?? 0 }}</p>
                </div>
                <div class="op-kpi">
                    <p class="op-kpi__label">Encerradas</p>
                    <p class="op-kpi__value op-kpi__value--green">{{ $todayStats['closed'] ?? 0 }}</p>
                </div>
                <div class="op-kpi">
                    <p class="op-kpi__label">Faturamento</p>
                    <p class="op-kpi__value" style="font-size: 1.1rem;">{{ $todayStats['revenue'] ?? 'R$ 0,00' }}</p>
                </div>
            </div>
        </section>

        <section>
            <p class="op-section-title">Minhas Ordens</p>
            <div class="op-kpi-grid" style="margin-top: 0.5rem;">
                <div class="op-kpi">
                    <p class="op-kpi__label">Pendentes</p>
                    <p class="op-kpi__value">{{ $myStats['pending'] ?? 0 }}</p>
                </div>
                <div class="op-kpi">
                    <p class="op-kpi__label">Agendadas Hoje</p>
                    <p class="op-kpi__value" style="color: #d97706;">{{ $myStats['scheduled_today'] ?? 0 }}</p>
                </div>
            </div>
        </section>

        <section>
            <p class="op-section-title">Acesso rápido</p>
            <div class="op-quick-grid" style="margin-top: 0.5rem;">
                <a href="{{ \App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue::getUrl() }}" class="op-quick" wire:navigate>
                    <div class="op-quick__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5h6"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 4h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2V5a1 1 0 0 1 1-1Z"/></svg>
                    </div>
                    <div>
                        <p class="op-quick__text">Ordens de Serviço</p>
                        <p class="op-quick__sub">Fila completa</p>
                    </div>
                </a>
                <a href="{{ \App\Filament\Operation\Pages\Requisitions\RequisitionList::getUrl() }}" class="op-quick" wire:navigate>
                    <div class="op-quick__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2Z"/></svg>
                    </div>
                    <div>
                        <p class="op-quick__text">Requisições</p>
                        <p class="op-quick__sub">Materiais</p>
                    </div>
                </a>
            </div>
        </section>

        @if (count($recentOrders) > 0)
            <section>
                <p class="op-section-title">Últimas OS abertas</p>
                <div class="op-recent" style="margin-top: 0.5rem;">
                    @foreach ($recentOrders as $order)
                        <a href="{{ $order['url'] }}" class="op-card" wire:navigate>
                            <div class="op-card__top">
                                <span class="op-card__number">#{{ $order['number'] }}</span>
                                <span class="op-card__badge op-card__badge--{{ $order['status_color'] }}">{{ $order['status'] }}</span>
                            </div>
                            <p class="op-card__sub">{{ $order['customer'] }} &middot; {{ $order['equipment'] }}</p>
                            <p class="op-card__sub">{{ $order['order_date'] }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
