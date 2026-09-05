<x-filament-panels::page>
    <style>
        .op-detail { display: grid; gap: 0.85rem; padding-bottom: 6rem; }
        .op-card { border: 1px solid rgba(228,228,231,0.6); border-radius: 1rem; padding: 0.85rem; background: #fff; }
        .op-card__head { color: #fff; background: linear-gradient(135deg, #18181b, #334155); border-radius: 1rem; padding: 0.85rem; }
        .op-card__title { margin: 0; font-size: 1.05rem; font-weight: 850; }
        .op-card__sub { margin: 0.25rem 0 0; color: rgba(255,255,255,0.78); font-size: 0.78rem; }
        .op-card__kpi-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0.4rem; margin-top: 0.75rem; }
        .op-card__kpi { border-radius: 0.75rem; padding: 0.5rem; background: rgba(255,255,255,0.1); }
        .op-card__kpi span { display: block; font-size: 0.62rem; font-weight: 700; opacity: 0.7; text-transform: uppercase; }
        .op-card__kpi strong { display: block; margin-top: 0.15rem; font-size: 0.78rem; }
        .op-badge { display: inline-block; border-radius: 999px; padding: 0.2rem 0.55rem; font-size: 0.68rem; font-weight: 700; }
        .op-badge--info { background: #dbeafe; color: #1d4ed8; }
        .op-badge--success { background: #dcfce7; color: #166534; }
        .op-badge--warning { background: #fef3c7; color: #92400e; }
        .op-badge--danger { background: #fee2e2; color: #b91c1c; }
        .op-badge--gray { background: #e5e7eb; color: #374151; }
        .op-section { margin-top: 0.5rem; }
        .op-section-title { font-size: 0.82rem; font-weight: 800; color: #0f172a; margin-bottom: 0.5rem; }
        .op-field { display: grid; gap: 0.25rem; margin-bottom: 0.5rem; }
        .op-field label { color: #64748b; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; }
        .op-field p { margin: 0; font-size: 0.82rem; font-weight: 600; color: #0f172a; }
        .op-items { display: grid; gap: 0.5rem; }
        .op-item { border-radius: 0.75rem; padding: 0.65rem; background: #f8fafc; }
        .op-item__name { font-size: 0.82rem; font-weight: 700; color: #0f172a; }
        .op-item__meta { font-size: 0.72rem; color: #64748b; margin-top: 0.15rem; }
        .op-input { width: 100%; min-height: 2.5rem; border: 1px solid rgba(228,228,231,0.6); border-radius: 0.75rem; padding: 0.5rem 0.7rem; background: #fff; color: #0f172a; font-size: 0.82rem; resize: vertical; }
        .op-textarea { min-height: 4rem; }
        .op-actions { position: fixed; right: 0; bottom: 0; left: 0; z-index: 60; display: grid; grid-template-columns: 1fr 1fr; gap: 0.4rem; padding: 0.65rem max(0.75rem, env(safe-area-inset-left)) max(0.65rem, env(safe-area-inset-bottom)); border-top: 1px solid rgba(228,228,231,0.5); background: rgba(248,250,252,0.96); backdrop-filter: blur(14px); }
        .op-btn { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; border: 0; border-radius: 0.8rem; font-size: 0.75rem; font-weight: 800; text-decoration: none; cursor: pointer; }
        .op-btn--secondary { background: #e2e8f0; color: #334155; }
        .op-btn--primary { background: #18181b; color: #fff; }
        .op-btn--success { background: #16a34a; color: #fff; }
        .op-btn--danger { background: #dc2626; color: #fff; }
        .op-btn:disabled { opacity: 0.5; }
        .op-empty { border-radius: 1rem; padding: 1.5rem; background: #fff; color: #64748b; text-align: center; font-size: 0.82rem; }
    </style>

    @if ($order)
        <div class="op-detail">
            <section class="op-card__head">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;">
                    <p class="op-card__title">OS #{{ $order['number'] }}</p>
                    <span class="op-badge op-badge--{{ $order['status_color'] }}">{{ $order['status_label'] }}</span>
                </div>
                <p class="op-card__sub">{{ $order['type'] }} &middot; {{ $order['priority'] }} &middot; {{ $order['order_date'] }}</p>
                <div class="op-card__kpi-row">
                    <div class="op-card__kpi">
                        <span>Valor</span>
                        <strong>{{ $order['total'] }}</strong>
                    </div>
                    <div class="op-card__kpi">
                        <span>Agendamento</span>
                        <strong>{{ $order['scheduled_date'] }}</strong>
                    </div>
                    <div class="op-card__kpi">
                        <span>Local</span>
                        <strong>{{ Str::limit($order['location'], 18) }}</strong>
                    </div>
                </div>
            </section>

            <section class="op-card">
                <div class="op-section-title">Cliente e Equipamento</div>
                <div class="op-field">
                    <label>Cliente</label>
                    <p>{{ $order['customer_name'] }} @if ($order['customer_doc'] !== '-') &middot; {{ $order['customer_doc'] }} @endif</p>
                </div>
                <div class="op-field">
                    <label>Equipamento</label>
                    <p>{{ $order['equipment_name'] }} @if ($order['equipment_identifier'] !== '-') &middot; {{ $order['equipment_identifier'] }} @endif</p>
                </div>
                <div class="op-field">
                    <label>Técnico</label>
                    <p>{{ $order['technician_name'] }}</p>
                </div>
            </section>

            @if (count($order['items']) > 0)
                <section class="op-card">
                    <div class="op-section-title">Itens ({{ count($order['items']) }})</div>
                    <div class="op-items">
                        @foreach ($order['items'] as $item)
                            <div class="op-item">
                                <p class="op-item__name">{{ $item['name'] }}</p>
                                <p class="op-item__meta">{{ $item['quantity'] }} x {{ $item['unit_price'] }} = {{ $item['total'] }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($order['can_edit'])
                <section class="op-card">
                    <div class="op-section-title">Registro do Atendimento</div>
                    <form wire:submit="save">
                        <div class="op-field">
                            <label>Solução Aplicada</label>
                            <textarea wire:model="formData.solution" class="op-input op-textarea" placeholder="Descreva a solução aplicada..."></textarea>
                        </div>
                        <div class="op-field">
                            <label>Observações do Técnico</label>
                            <textarea wire:model="formData.technician_observations" class="op-input op-textarea" placeholder="Observações internas..."></textarea>
                        </div>
                    </form>
                </section>
            @else
                @if ($order['solution'])
                    <section class="op-card">
                        <div class="op-section-title">Solução Aplicada</div>
                        <p style="font-size: 0.82rem; color: #334155;">{{ $order['solution'] }}</p>
                    </section>
                @endif

                @if ($order['technician_observations'])
                    <section class="op-card">
                        <div class="op-section-title">Observações do Técnico</div>
                        <p style="font-size: 0.82rem; color: #334155;">{{ $order['technician_observations'] }}</p>
                    </section>
                @endif
            @endif
        </div>

        @if ($order['can_edit'])
            <div class="op-actions">
                <a href="{{ $order['list_url'] }}" class="op-btn op-btn--secondary" wire:navigate>Voltar</a>
                <button type="button" wire:click="save" class="op-btn op-btn--primary" wire:loading.attr="disabled">Salvar</button>
                @if ($order['is_open'])
                    <button type="button" wire:click="close" wire:confirm="Encerrar esta ordem de serviço?" class="op-btn op-btn--success" wire:loading.attr="disabled">Encerrar</button>
                    <button type="button" wire:click="cancel" wire:confirm="Cancelar esta ordem de serviço?" class="op-btn op-btn--danger" wire:loading.attr="disabled">Cancelar</button>
                @endif
            </div>
        @else
            <div class="op-actions">
                <a href="{{ $order['list_url'] }}" class="op-btn op-btn--secondary" style="grid-column: 1 / -1;" wire:navigate>Voltar</a>
            </div>
        @endif
    @else
        <div class="op-empty">Ordem de serviço não encontrada.</div>
        <div class="op-actions">
            <a href="{{ \App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue::getUrl() }}" class="op-btn op-btn--secondary" style="grid-column: 1 / -1;" wire:navigate>Voltar</a>
        </div>
    @endif
</x-filament-panels::page>
