<x-filament-panels::page>
    <style>
        .op-queue { display: grid; gap: 0.75rem; }
        .op-search { width: 100%; min-height: 2.75rem; border: 1px solid rgba(228,228,231,0.6); border-radius: 0.95rem; padding: 0.55rem 0.85rem; background: #fff; font-size: 0.82rem; color: #0f172a; }
        .op-search::placeholder { color: #94a3b8; }
        .op-tabs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.4rem; }
        .op-tab { min-height: 2.8rem; border: 0; border-radius: 0.85rem; background: #e2e8f0; color: #334155; font-size: 0.72rem; font-weight: 700; cursor: pointer; transition: background 0.15s, color 0.15s; }
        .op-tab span { display: block; margin-top: 0.15rem; font-size: 0.62rem; opacity: 0.7; }
        .op-tab.is-active { background: #18181b; color: #fff; }
        .op-list { display: grid; gap: 0.6rem; }
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
        .op-card__customer { margin-top: 0.3rem; font-size: 0.78rem; font-weight: 600; color: #334155; }
        .op-card__meta { display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem; margin-top: 0.45rem; }
        .op-card__meta-item { color: #64748b; font-size: 0.68rem; }
        .op-card__meta-item strong { font-weight: 700; color: #475569; }
        .op-empty { border-radius: 1rem; padding: 1.5rem; background: #fff; color: #64748b; text-align: center; font-size: 0.82rem; }
    </style>

    <div class="op-queue">
        <input
            type="text"
            wire:model.live.debounce.300ms="search"
            placeholder="Buscar por número, cliente, equipamento..."
            class="op-search"
        >

        <div class="op-tabs">
            <button type="button" wire:click="setTab('open')" class="op-tab @if ($activeTab === 'open') is-active @endif">
                Abertas<span>{{ $openCount }}</span>
            </button>
            <button type="button" wire:click="setTab('closed')" class="op-tab @if ($activeTab === 'closed') is-active @endif">
                Encerradas<span>{{ $closedCount }}</span>
            </button>
            <button type="button" wire:click="setTab('all')" class="op-tab @if ($activeTab === 'all') is-active @endif">
                Todas<span>{{ $allCount }}</span>
            </button>
        </div>

        <div class="op-list">
            @forelse ($orders as $order)
                <a href="{{ $order['url'] }}" class="op-card" wire:navigate>
                    <div class="op-card__top">
                        <span class="op-card__number">#{{ $order['number'] }}</span>
                        <span class="op-card__badge {{ $this->getStatusBadgeClass($order['status_color']) }}">{{ $order['status'] }}</span>
                    </div>
                    <p class="op-card__customer">{{ $order['customer'] }}</p>
                    <div class="op-card__meta">
                        <span class="op-card__meta-item"><strong>{{ $order['equipment'] }}</strong></span>
                        <span class="op-card__meta-item"><strong>{{ $order['technician'] }}</strong></span>
                        <span class="op-card__meta-item">{{ $order['order_date'] }}</span>
                        <span class="op-card__meta-item"><strong>{{ $order['total'] }}</strong></span>
                    </div>
                </a>
            @empty
                <div class="op-empty">Nenhuma ordem encontrada.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
