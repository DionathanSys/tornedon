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
        .pr-mob-bottom { position: fixed; right: 0; bottom: 0; left: 0; z-index: 60; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; padding: .75rem max(.75rem, env(safe-area-inset-left)) max(.75rem, env(safe-area-inset-bottom)); border-top: 1px solid rgba(148, 163, 184, .25); background: rgba(248, 250, 252, .96); backdrop-filter: blur(14px); }
        .pr-mob-bottom a, .pr-mob-bottom button { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; border: 0; border-radius: .85rem; font-size: .72rem; font-weight: 850; text-decoration: none; }
        .pr-mob-secondary { background: #e2e8f0; color: #334155; }
        .pr-mob-save { background: #111827; color: #fff; }
        .pr-mob-danger { background: #fee2e2; color: #b91c1c; }
    </style>

    <form wire:submit="save">
        <div class="pr-mob-detail">
            <section class="pr-mob-card pr-mob-head">
                <p class="pr-mob-title">{{ $record->counterparty_label }}</p>
                <p class="pr-mob-sub">{{ $record->document_number ?: 'Sem documento' }} - {{ $record->due_date?->format('d/m/Y') ?? '-' }}</p>
                <div class="pr-mob-kpis">
                    <div><span>Status</span><strong>{{ $record->status?->description() ?? '-' }}</strong></div>
                    <div><span>Valor</span><strong>R$ {{ number_format((float) $record->due_amount, 2, ',', '.') }}</strong></div>
                    <div><span>Pago</span><strong>R$ {{ number_format((float) $record->paid_amount, 2, ',', '.') }}</strong></div>
                </div>
            </section>

            <section class="pr-mob-card">
                {{ $this->form }}
            </section>
        </div>

        <section class="pr-mob-bottom">
            <a href="{{ $this->getListUrl() }}" class="pr-mob-secondary">Lista</a>
            <button type="submit" class="pr-mob-save" wire:loading.attr="disabled">Salvar</button>
            <button type="button" wire:click="deleteRecord" class="pr-mob-danger" wire:confirm="Excluir esta conta a pagar?">Excluir</button>
        </section>
    </form>
</x-filament-panels::page>
