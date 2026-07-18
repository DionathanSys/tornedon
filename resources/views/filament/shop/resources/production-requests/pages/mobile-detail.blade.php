<x-filament-panels::page>
    <style>
        .pr-mob-detail { display: grid; gap: .85rem; padding-bottom: 6.25rem; }
        .pr-mob-card { border: 1px solid rgba(148, 163, 184, .25); border-radius: 1.1rem; padding: .95rem; background: #fff; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-head { color: #fff; background: linear-gradient(135deg, #111827, #334155); }
        .pr-mob-title { margin: 0; font-size: 1.05rem; font-weight: 850; }
        .pr-mob-sub { margin: .3rem 0 0; color: rgba(255,255,255,.78); font-size: .78rem; }
        .pr-mob-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; margin-top: .85rem; }
        .pr-mob-kpis div { border-radius: .75rem; padding: .55rem; background: rgba(255,255,255,.1); }
        .pr-mob-kpis span { display: block; font-size: .62rem; font-weight: 800; opacity: .7; text-transform: uppercase; }
        .pr-mob-kpis strong { display: block; margin-top: .2rem; font-size: .8rem; }
        .pr-mob-section-title { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .75rem; }
        .pr-mob-section-title h3 { margin: 0; font-size: .95rem; font-weight: 850; }
        .pr-mob-small-btn { border: 0; border-radius: .75rem; padding: .55rem .75rem; background: #111827; color: #fff; font-size: .75rem; font-weight: 800; }
        .pr-mob-item-form { display: grid; gap: .65rem; margin-bottom: .8rem; padding: .75rem; border-radius: .9rem; background: #f8fafc; }
        .pr-mob-field { display: grid; gap: .3rem; }
        .pr-mob-field label { color: #475569; font-size: .72rem; font-weight: 800; }
        .pr-mob-field input, .pr-mob-field select { width: 100%; min-height: 2.85rem; border: 1px solid rgba(148, 163, 184, .45); border-radius: .85rem; padding: .55rem .7rem; background: #fff; color: #0f172a; }
        .pr-mob-quantity { display: grid; grid-template-columns: 2.85rem 1fr 2.85rem; gap: .4rem; }
        .pr-mob-quantity button { min-height: 2.85rem; border: 1px solid rgba(148, 163, 184, .45); border-radius: .85rem; background: #fff; color: #0f172a; font-size: 1.1rem; font-weight: 900; }
        .pr-mob-quantity input { text-align: center; }
        .pr-mob-item-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; }
        .pr-mob-item-actions button { min-height: 2.75rem; border: 0; border-radius: .75rem; font-size: .72rem; font-weight: 850; }
        .pr-mob-secondary { background: #e2e8f0; color: #334155; }
        .pr-mob-save { background: #111827; color: #fff; }
        .pr-mob-list { display: grid; gap: .6rem; }
        .pr-mob-item { display: grid; gap: .55rem; border-radius: .9rem; padding: .75rem; background: #f8fafc; }
        .pr-mob-item strong { font-size: .88rem; }
        .pr-mob-item span { color: #64748b; font-size: .76rem; }
        .pr-mob-item-footer { display: grid; grid-template-columns: 1fr 1fr; gap: .45rem; }
        .pr-mob-item-footer button { min-height: 2.5rem; border: 0; border-radius: .7rem; font-size: .72rem; font-weight: 850; }
        .pr-mob-bottom { position: fixed; right: 0; bottom: 0; left: 0; z-index: 60; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .45rem; padding: .75rem max(.75rem, env(safe-area-inset-left)) max(.75rem, env(safe-area-inset-bottom)); border-top: 1px solid rgba(148, 163, 184, .25); background: rgba(248, 250, 252, .96); backdrop-filter: blur(14px); }
        .pr-mob-bottom a, .pr-mob-bottom button { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; border: 0; border-radius: .85rem; font-size: .72rem; font-weight: 850; text-decoration: none; }
        .pr-mob-danger { background: #fee2e2; color: #b91c1c; }
        .pr-mob-success { background: #dcfce7; color: #166534; }
    </style>

    <div class="pr-mob-detail">
        <section class="pr-mob-card pr-mob-head">
            <p class="pr-mob-title">{{ $record->counterparty_label }}</p>
            <p class="pr-mob-sub">{{ $record->status->description() }} - {{ $record->order_date?->format('d/m/Y') ?? '-' }}</p>
            <div class="pr-mob-kpis">
                <div><span>Total</span><strong>R$ {{ number_format((float) $record->total_amount, 2, ',', '.') }}</strong></div>
                <div><span>Itens</span><strong>{{ $record->items->count() }}</strong></div>
                <div><span>Entrega</span><strong>{{ $record->delivered_at?->format('d/m') ?? '-' }}</strong></div>
            </div>
        </section>

        <section class="pr-mob-card">
            <form wire:submit="save">
                {{ $this->form }}
            </form>
        </section>

        <section class="pr-mob-card">
            <div class="pr-mob-section-title">
                <h3>Itens</h3>
                @if ($record->status === \App\Enum\ProductionRequest\Status::OPEN)
                    <button type="button" wire:click="toggleItemForm" class="pr-mob-small-btn">Adicionar</button>
                @endif
            </div>

            @if ($showItemForm)
                <div class="pr-mob-item-form">
                    <div class="pr-mob-field">
                        <label>Produto</label>
                        <select wire:model.live="itemData.product_id">
                            <option value="">Selecione</option>
                            @foreach ($this->productOptions as $productId => $productName)
                                <option value="{{ $productId }}">{{ $productName }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="pr-mob-field"><label>Unidade</label><input type="text" wire:model="itemData.unit_of_measure"></div>
                    <div class="pr-mob-field">
                        <label>Quantidade</label>
                        <div class="pr-mob-quantity">
                            <button type="button" wire:click="decrementItemQuantity" aria-label="Diminuir quantidade">-</button>
                            <input type="text" inputmode="decimal" wire:model="itemData.quantity">
                            <button type="button" wire:click="incrementItemQuantity" aria-label="Aumentar quantidade">+</button>
                        </div>
                    </div>
                    <div class="pr-mob-field"><label>Valor unitário</label><input type="text" inputmode="decimal" wire:model="itemData.unit_price"></div>
                    <div class="pr-mob-item-actions">
                        <button type="button" wire:click="$set('showItemForm', false)" class="pr-mob-secondary">Cancelar</button>
                        <button type="button" wire:click="saveItem(true)" class="pr-mob-secondary">Salvar +</button>
                        <button type="button" wire:click="saveItem(false)" class="pr-mob-save">Salvar</button>
                    </div>
                </div>
            @endif

            <div class="pr-mob-list">
                @forelse ($record->items as $item)
                    <div class="pr-mob-item">
                        <strong>{{ $item->description ?: $item->product?->name }}</strong>
                        <span>{{ number_format((float) $item->quantity, 3, ',', '.') }} {{ $item->unit_of_measure }} - R$ {{ number_format((float) $item->total_amount, 2, ',', '.') }}</span>
                        @if ($record->status === \App\Enum\ProductionRequest\Status::OPEN)
                            <div class="pr-mob-item-footer">
                                <button type="button" wire:click="editItem({{ $item->id }})" class="pr-mob-secondary">Editar</button>
                                <button type="button" wire:click="deleteItem({{ $item->id }})" class="pr-mob-danger">Excluir</button>
                            </div>
                        @endif
                    </div>
                @empty
                    <div class="pr-mob-item"><span>Nenhum item adicionado.</span></div>
                @endforelse
            </div>
        </section>
    </div>

    <div class="pr-mob-bottom">
        <a href="{{ $this->getListUrl() }}" class="pr-mob-secondary">Lista</a>
        <button type="button" wire:click="save" class="pr-mob-save">Salvar</button>
        @if ($record->status === \App\Enum\ProductionRequest\Status::OPEN)
            <button type="button" wire:click="deliver" class="pr-mob-success">Entregar</button>
            <button type="button" wire:click="cancel" class="pr-mob-danger">Cancelar</button>
        @else
            <button type="button" disabled class="pr-mob-secondary">Entregue</button>
            <button type="button" disabled class="pr-mob-secondary">Cancelar</button>
        @endif
    </div>
</x-filament-panels::page>
