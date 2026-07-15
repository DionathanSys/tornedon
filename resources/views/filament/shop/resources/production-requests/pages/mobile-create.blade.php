<x-filament-panels::page>
    <style>
        .pr-mob-form-page { display: grid; gap: 1rem; padding-bottom: 6rem; }
        .pr-mob-form-card { border: 1px solid rgba(148, 163, 184, .25); border-radius: 1.15rem; padding: 1rem; background: #fff; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-bottom { position: fixed; right: 0; bottom: 0; left: 0; z-index: 60; display: grid; grid-template-columns: 1fr 1.4fr; gap: .5rem; padding: .75rem max(.75rem, env(safe-area-inset-left)) max(.75rem, env(safe-area-inset-bottom)); border-top: 1px solid rgba(148, 163, 184, .25); background: rgba(248, 250, 252, .96); backdrop-filter: blur(14px); }
        .pr-mob-link, .pr-mob-primary { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; border-radius: .9rem; font-weight: 800; text-decoration: none; }
        .pr-mob-link { background: #e2e8f0; color: #334155; }
        .pr-mob-primary { border: 0; background: #111827; color: #fff; }
    </style>

    <form wire:submit="save" class="pr-mob-form-page">
        <section class="pr-mob-form-card">
            {{ $this->form }}
        </section>

        <div class="pr-mob-bottom">
            <a href="{{ $this->getListUrl() }}" class="pr-mob-link">Cancelar</a>
            <button type="submit" class="pr-mob-primary" wire:loading.attr="disabled">Criar pedido</button>
        </div>
    </form>
</x-filament-panels::page>
