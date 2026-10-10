<x-filament-panels::page>
    <style>
        .op-detail { display: grid; gap: 0.85rem; }
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
        .op-empty { border-radius: 1rem; padding: 1.5rem; background: #fff; color: #64748b; text-align: center; font-size: 0.82rem; }
    </style>

    @if ($order)
        <div class="op-detail">
            <section class="op-card__head">
                <p class="op-card__title">OS #{{ $order['number'] }}</p>
                <p class="op-card__sub">{{ $order['type'] }} &middot; {{ $order['priority'] }} &middot; {{ $order['order_date'] }}</p>
                <div class="op-card__kpi-row">
                    <div class="op-card__kpi">
                        <span>Valor</span>
                        <strong>{{ $order['total'] }}</strong>
                    </div>
                    <div class="op-card__kpi">
                        <span>Status</span>
                        <strong>{{ $order['status_label'] }}</strong>
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
                @if (! $order['can_edit'])
                    <div class="op-field">
                        <label>Equipamento</label>
                        <p>{{ $order['equipment_name'] }} @if ($order['equipment_identifier'] !== '-') &middot; {{ $order['equipment_identifier'] }} @endif</p>
                    </div>
                    <div class="op-field">
                        <label>Técnico</label>
                        <p>{{ $order['technician_name'] }}</p>
                    </div>
                @endif
            </section>

            <section class="op-card">
                <div class="op-section-title">Serviços ({{ count($order['items']) }})</div>
                <div class="op-items">
                    @forelse ($order['items'] as $item)
                        <div class="op-item" wire:key="service-order-item-{{ $item['id'] }}">
                            <p class="op-item__name">{{ $item['name'] }}</p>
                            <p class="op-item__meta">{{ $item['quantity'] }} x {{ $item['unit_price'] }} = {{ $item['total'] }}</p>
                            @if ($item['observations'])
                                <p class="op-item__meta">{{ $item['observations'] }}</p>
                            @endif
                            @if ($order['is_open'])
                                <div style="margin-top: 0.5rem;">{{ ($this->editServiceAction)(['item' => $item['id']]) }}</div>
                            @endif
                        </div>
                    @empty
                        <p class="op-item__meta">Nenhum serviço adicionado.</p>
                    @endforelse
                </div>
            </section>

            @if ($order['can_edit'])
                <form wire:submit="save">
                    {{ $this->form }}
                </form>
            @else
                <x-filament::section heading="Registro do Atendimento" collapsible collapsed>
                    @foreach (['customer_observations' => 'Observações do Cliente', 'items_received' => 'Itens recebidos', 'general_observations' => 'Observações gerais', 'solution' => 'Solução Aplicada', 'technician_observations' => 'Observações do Técnico'] as $field => $label)
                        <div class="op-field">
                            <label>{{ $label }}</label>
                            <p>{{ $order[$field] ?: '-' }}</p>
                        </div>
                    @endforeach
                </x-filament::section>
            @endif
        </div>

        @include('filament.operation.floating-action', ['action' => $this->addServiceAction])

    @else
        <div class="op-empty">Ordem de serviço não encontrada.</div>
    @endif
</x-filament-panels::page>
