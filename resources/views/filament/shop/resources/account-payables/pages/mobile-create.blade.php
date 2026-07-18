<x-filament-panels::page>
    <style>
        .pr-mob-detail { display: grid; gap: .85rem; padding-bottom: 6.25rem; }
        .pr-mob-card { border: 1px solid rgba(148, 163, 184, .25); border-radius: 1.1rem; padding: .95rem; background: #fff; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-head { color: #fff; background: linear-gradient(135deg, #111827, #334155); }
        .pr-mob-title { margin: 0; font-size: 1.05rem; font-weight: 850; }
        .pr-mob-sub { margin: .3rem 0 0; color: rgba(255,255,255,.78); font-size: .78rem; }
        .pr-mob-bottom { position: fixed; right: 0; bottom: 0; left: 0; z-index: 60; display: grid; grid-template-columns: 1fr 1fr; gap: .45rem; padding: .75rem max(.75rem, env(safe-area-inset-left)) max(.75rem, env(safe-area-inset-bottom)); border-top: 1px solid rgba(148, 163, 184, .25); background: rgba(248, 250, 252, .96); backdrop-filter: blur(14px); }
        .pr-mob-bottom a, .pr-mob-bottom button { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; border: 0; border-radius: .85rem; font-size: .72rem; font-weight: 850; text-decoration: none; }
        .pr-mob-secondary { background: #e2e8f0; color: #334155; }
        .pr-mob-save { background: #111827; color: #fff; }
    </style>

    <form wire:submit="create">
        <div class="pr-mob-detail">
            <section class="pr-mob-card pr-mob-head">
                <p class="pr-mob-title">Novo Contas à Pagar</p>
                <p class="pr-mob-sub">Preencha os dados do pagamento para criar a conta.</p>
            </section>

            <section class="pr-mob-card">
                {{ $this->form }}
            </section>
        </div>

        <section class="pr-mob-bottom">
            <a href="{{ $this->getListUrl() }}" class="pr-mob-secondary">Lista</a>
            <button type="submit" class="pr-mob-save" wire:loading.attr="disabled">Criar</button>
        </section>
    </form>
</x-filament-panels::page>
