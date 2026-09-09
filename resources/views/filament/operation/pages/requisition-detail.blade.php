<x-filament-panels::page>
    <style>
        .op-detail { display: grid; gap: 0.85rem; padding-bottom: 5rem; }
        .op-card { border: 1px solid rgba(228,228,231,0.6); border-radius: 1rem; padding: 0.85rem; background: #fff; }
        .op-card__head { color: #fff; background: linear-gradient(135deg, #18181b, #334155); border-radius: 1rem; padding: 0.85rem; }
        .op-card__title { margin: 0; font-size: 1.05rem; font-weight: 850; }
        .op-card__sub { margin: 0.25rem 0 0; color: rgba(255,255,255,0.78); font-size: 0.78rem; }
        .op-badge { display: inline-block; border-radius: 999px; padding: 0.2rem 0.55rem; font-size: 0.68rem; font-weight: 700; }
        .op-badge--info { background: #dbeafe; color: #1d4ed8; }
        .op-badge--success { background: #dcfce7; color: #166534; }
        .op-badge--warning { background: #fef3c7; color: #92400e; }
        .op-badge--danger { background: #fee2e2; color: #b91c1c; }
        .op-badge--gray { background: #e5e7eb; color: #374151; }
        .op-section-title { font-size: 0.82rem; font-weight: 800; color: #0f172a; margin-bottom: 0.5rem; }
        .op-field { display: grid; gap: 0.25rem; margin-bottom: 0.5rem; }
        .op-field label { color: #64748b; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; }
        .op-field p { margin: 0; font-size: 0.82rem; font-weight: 600; color: #0f172a; }
        .op-items { display: grid; gap: 0.5rem; }
        .op-item { border-radius: 0.75rem; padding: 0.65rem; background: #f8fafc; }
        .op-item__top { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }
        .op-item__name { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
        .op-item__stock { font-size: 0.62rem; font-weight: 700; border-radius: 999px; padding: 0.1rem 0.4rem; }
        .op-item__stock--consumed { background: #dcfce7; color: #166534; }
        .op-item__stock--pending { background: #fef3c7; color: #92400e; }
        .op-item__meta { font-size: 0.72rem; color: #64748b; margin-top: 0.15rem; }
        .op-actions { position: fixed; right: 0; bottom: 0; left: 0; z-index: 60; display: grid; grid-template-columns: 1fr; gap: 0.4rem; padding: 0.65rem max(0.75rem, env(safe-area-inset-left)) max(0.65rem, env(safe-area-inset-bottom)); border-top: 1px solid rgba(228,228,231,0.5); background: rgba(248,250,252,0.96); backdrop-filter: blur(14px); }
        .op-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; border: 0; border-radius: 0.8rem; font-size: 0.75rem; font-weight: 800; text-decoration: none; }
        .op-btn--secondary { background: #e2e8f0; color: #334155; }
        .op-btn--success { background: #16a34a; color: #fff; }
        .op-btn--danger { background: #dc2626; color: #fff; }
        .op-empty { border-radius: 1rem; padding: 1.5rem; background: #fff; color: #64748b; text-align: center; font-size: 0.82rem; }
    </style>

    @if ($requisition)
        <div class="op-detail">
            <section class="op-card__head">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                    <p class="op-card__title">{{ $requisition['number'] }}</p>
                    <span class="op-badge {{ $this->getStatusBadgeClass($requisition['status_value']) }}">{{ $requisition['status'] }}</span>
                </div>
                <p class="op-card__sub">{{ $requisition['sale_date'] }} &middot; Total: {{ $requisition['total'] }}</p>
            </section>

            <section class="op-card">
                <div class="op-section-title">Cliente e Equipamento</div>
                <div class="op-field">
                    <label>Cliente</label>
                    <p>{{ $requisition['customer_name'] }} @if ($requisition['customer_doc'] !== '-') &middot; {{ $requisition['customer_doc'] }} @endif</p>
                </div>
                <div class="op-field">
                    <label>Equipamento</label>
                    <p>{{ $requisition['equipment_name'] }} @if ($requisition['equipment_identifier'] !== '-') &middot; {{ $requisition['equipment_identifier'] }} @endif</p>
                </div>
                <div class="op-field">
                    <label>Ordem de Serviço</label>
                    <p>#{{ $requisition['service_order_number'] }}</p>
                </div>
            </section>

            @if (count($requisition['items']) > 0)
                <section class="op-card">
                    <div class="op-section-title">Itens ({{ count($requisition['items']) }})</div>
                    <div class="op-items">
                        @foreach ($requisition['items'] as $item)
                            <div class="op-item">
                                <div class="op-item__top">
                                    <p class="op-item__name">{{ $item['name'] }}</p>
                                    @if ($item['stock_consumed'])
                                        <span class="op-item__stock op-item__stock--consumed">Estoque baixado</span>
                                    @else
                                        <span class="op-item__stock op-item__stock--pending">Pendente</span>
                                    @endif
                                </div>
                                <p class="op-item__meta">{{ $item['quantity'] }} {{ $item['unit'] }} x {{ $item['unit_price'] }} = {{ $item['total'] }}</p>
                                <p class="op-item__meta">Código: {{ $item['code'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($requisition['observations'])
                <section class="op-card">
                    <div class="op-section-title">Observações</div>
                    <p style="font-size: 0.82rem; color: #334155;">{{ $requisition['observations'] }}</p>
                </section>
            @endif
        </div>

        <div class="op-actions" style="grid-template-columns: 1fr 1fr;">
            <a href="{{ $requisition['list_url'] }}" class="op-btn op-btn--secondary" wire:navigate>Voltar</a>
            @if ($requisition['is_open'])
                <button type="button" wire:click="close" wire:confirm="Encerrar esta requisição?" class="op-btn op-btn--success" wire:loading.attr="disabled">Encerrar</button>
                <button type="button" wire:click="cancel" wire:confirm="Cancelar esta requisição?" class="op-btn op-btn--danger" wire:loading.attr="disabled" style="grid-column: 1 / -1;">Cancelar</button>
            @endif
        </div>
    @else
        <div class="op-empty">Requisição não encontrada.</div>
        <div class="op-actions">
            <a href="{{ \App\Filament\Operation\Pages\Requisitions\RequisitionList::getUrl() }}" class="op-btn op-btn--secondary" wire:navigate>Voltar</a>
        </div>
    @endif
</x-filament-panels::page>
