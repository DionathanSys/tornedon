<x-filament-panels::page>
    <style>
        .pr-mob-page { display: grid; gap: 1rem; padding-bottom: 1rem; }
        .pr-mob-new { display: inline-flex; align-items: center; justify-content: center; min-height: 3.4rem; border-radius: 1rem; background: #111827; color: #fff; font-weight: 800; text-decoration: none; }
        .pr-mob-tabs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; }
        .pr-mob-tab { min-height: 3.3rem; border: 0; border-radius: .9rem; background: #e2e8f0; color: #334155; font-size: .75rem; font-weight: 800; }
        .pr-mob-tab span { display: block; margin-top: .2rem; font-size: .7rem; opacity: .8; }
        .pr-mob-tab.is-active { background: #111827; color: #fff; }
        .pr-mob-list { display: grid; gap: .75rem; }
        .pr-mob-card { display: grid; gap: .75rem; border: 1px solid rgba(148, 163, 184, .25); border-radius: 1rem; padding: .95rem; background: #fff; color: #0f172a; text-decoration: none; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-mob-card__top { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .pr-mob-card__title { margin: 0; font-size: .98rem; font-weight: 800; }
        .pr-mob-card__sub { margin: .25rem 0 0; color: #64748b; font-size: .76rem; }
        .pr-mob-badge { white-space: nowrap; border-radius: 999px; padding: .25rem .55rem; background: #dcfce7; color: #166534; font-size: .7rem; font-weight: 800; }
        .pr-mob-badge--danger { background: #fee2e2; color: #b91c1c; }
        .pr-mob-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .pr-mob-meta div { border-radius: .75rem; padding: .55rem .65rem; background: #f8fafc; }
        .pr-mob-meta span { display: block; color: #64748b; font-size: .65rem; font-weight: 800; text-transform: uppercase; }
        .pr-mob-meta strong { display: block; margin-top: .2rem; font-size: .82rem; }
        .pr-mob-actions { display: grid; grid-template-columns: 1fr; gap: .5rem; }
        .pr-mob-action { display: inline-flex; align-items: center; justify-content: center; min-height: 2.65rem; border: 0; border-radius: .85rem; background: #e2e8f0; color: #334155; font-size: .78rem; font-weight: 800; text-decoration: none; }
        .pr-mob-empty { border-radius: 1rem; padding: 1rem; background: #fff; color: #64748b; text-align: center; }
    </style>

    <div class="pr-mob-page">
        <a href="{{ $this->getCreateUrl() }}" class="pr-mob-new">Novo Movimento</a>

        <div class="pr-mob-tabs">
            <button type="button" wire:click="setTab('{{ \App\Enum\Financial\CashMovementDirection::INFLOW->value }}')" class="pr-mob-tab @if ($activeTab === \App\Enum\Financial\CashMovementDirection::INFLOW->value) is-active @endif">Entradas<span>{{ $this->inflowCount }}</span></button>
            <button type="button" wire:click="setTab('{{ \App\Enum\Financial\CashMovementDirection::OUTFLOW->value }}')" class="pr-mob-tab @if ($activeTab === \App\Enum\Financial\CashMovementDirection::OUTFLOW->value) is-active @endif">Saídas<span>{{ $this->outflowCount }}</span></button>
            <button type="button" wire:click="setTab('all')" class="pr-mob-tab @if ($activeTab === 'all') is-active @endif">Todas<span>{{ $this->allCount }}</span></button>
        </div>

        <div class="pr-mob-list">
            @forelse ($this->cashMovements as $movement)
                <div class="pr-mob-card">
                    <div class="pr-mob-card__top">
                        <div>
                            <p class="pr-mob-card__title">{{ $movement->description ?: 'Movimento' }}</p>
                            <p class="pr-mob-card__sub">{{ $movement->transaction_date?->format('d/m/Y') ?? '-' }} - {{ $movement->financialAccount?->name ?? 'Sem conta' }}</p>
                        </div>
                        <span @class(['pr-mob-badge', 'pr-mob-badge--danger' => $movement->direction === \App\Enum\Financial\CashMovementDirection::OUTFLOW])>{{ $movement->direction?->description() ?? '-' }}</span>
                    </div>

                    <div class="pr-mob-meta">
                        <div><span>Valor</span><strong>R$ {{ number_format((float) $movement->amount, 2, ',', '.') }}</strong></div>
                        <div><span>Categoria</span><strong>{{ $movement->financialCategory?->full_name ?? '-' }}</strong></div>
                    </div>

                    <div class="pr-mob-actions">
                        <a href="{{ $this->getDetailUrl($movement) }}" class="pr-mob-action">Ver detalhes</a>
                    </div>
                </div>
            @empty
                <div class="pr-mob-empty">Nenhum movimento encontrado.</div>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
