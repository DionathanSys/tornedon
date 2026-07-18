<x-filament-panels::page>
    <style>
        .pr-report-page { display: grid; gap: .85rem; padding-bottom: 1rem; }
        .pr-report-card { border: 1px solid rgba(148, 163, 184, .25); border-radius: 1.1rem; padding: .95rem; background: #fff; box-shadow: 0 16px 40px -34px rgba(15, 23, 42, .22); }
        .pr-report-head { color: #fff; background: linear-gradient(135deg, #111827, #334155); }
        .pr-report-title { margin: 0; font-size: 1.05rem; font-weight: 850; }
        .pr-report-sub { margin: .3rem 0 0; color: rgba(255,255,255,.78); font-size: .78rem; }
        .pr-report-kpis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .45rem; margin-top: .85rem; }
        .pr-report-kpis div { border-radius: .75rem; padding: .55rem; background: rgba(255,255,255,.1); }
        .pr-report-kpis span { display: block; font-size: .62rem; font-weight: 800; opacity: .7; text-transform: uppercase; }
        .pr-report-kpis strong { display: block; margin-top: .2rem; font-size: .8rem; }
        .pr-report-actions { display: grid; grid-template-columns: 1fr; gap: .5rem; }
        .pr-report-actions a { display: inline-flex; align-items: center; justify-content: center; min-height: 3rem; border-radius: .85rem; background: #e2e8f0; color: #334155; font-size: .78rem; font-weight: 850; text-decoration: none; }
        .pr-report-list { display: grid; gap: .6rem; }
        .pr-report-item { display: grid; gap: .55rem; border-radius: .95rem; padding: .8rem; background: #f8fafc; }
        .pr-report-item strong { color: #0f172a; font-size: .9rem; }
        .pr-report-item__meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .45rem; }
        .pr-report-item__meta div { border-radius: .75rem; padding: .55rem; background: #fff; }
        .pr-report-item__meta span { display: block; color: #64748b; font-size: .64rem; font-weight: 800; text-transform: uppercase; }
        .pr-report-item__meta b { display: block; margin-top: .2rem; color: #111827; font-size: .82rem; }
        .pr-report-empty { border-radius: 1rem; padding: 1rem; background: #fff; color: #64748b; text-align: center; }
    </style>

    <div class="pr-report-page">
        <section class="pr-report-card pr-report-head">
            <p class="pr-report-title">Resumo dos pedidos em aberto</p>
            <p class="pr-report-sub">Produtos agrupados pela soma das quantidades pendentes.</p>

            <div class="pr-report-kpis">
                <div><span>Pedidos</span><strong>{{ $this->openOrdersCount }}</strong></div>
                <div><span>Produtos</span><strong>{{ $this->productsCount }}</strong></div>
                <div><span>Quantidade</span><strong>{{ number_format((float) $this->totalQuantity, 3, ',', '.') }}</strong></div>
            </div>
        </section>

        <section class="pr-report-actions">
            <a href="{{ $this->getListUrl() }}">Voltar para pedidos</a>
        </section>

        <section class="pr-report-card">
            <div class="pr-report-list">
                @forelse ($this->rows as $row)
                    <div class="pr-report-item">
                        <strong>{{ $row->product_name }}</strong>

                        <div class="pr-report-item__meta">
                            <div>
                                <span>Quantidade</span>
                                <b>{{ number_format((float) $row->total_quantity, 3, ',', '.') }} {{ $row->unit_of_measure }}</b>
                            </div>
                            <div>
                                <span>Pedidos</span>
                                <b>{{ (int) $row->orders_count }}</b>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="pr-report-empty">Nenhum produto em pedidos abertos.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-filament-panels::page>
