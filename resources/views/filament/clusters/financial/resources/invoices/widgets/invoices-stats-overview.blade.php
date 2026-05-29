<x-filament-widgets::widget>
    <style>
        .invoice-overview {
            display: grid;
            gap: 1rem;
        }

        .invoice-overview__hero {
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            padding: 1.5rem;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(135deg, #09090b 0%, #18181b 52%, #27272a 100%);
            box-shadow: 0 24px 80px -32px rgba(15, 23, 42, 0.85);
        }

        .invoice-overview__hero::before {
            content: '';
            position: absolute;
            inset: auto -60px -60px auto;
            width: 180px;
            height: 180px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.18), transparent 70%);
        }

        .invoice-overview__hero-content {
            position: relative;
            display: grid;
            gap: 1.25rem;
        }

        .invoice-overview__top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }

        .invoice-overview__eyebrow {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 0.75rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #e4e4e7;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .invoice-overview__subtitle {
            margin: 0.75rem 0 0;
            font-size: 0.92rem;
            color: #d4d4d8;
        }

        .invoice-overview__amount {
            margin: 0.35rem 0 0;
            font-size: clamp(2rem, 3vw, 3rem);
            line-height: 1.05;
            font-weight: 700;
            letter-spacing: -0.04em;
        }

        .invoice-overview__context {
            min-width: 160px;
            padding: 0.85rem 1rem;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            text-align: right;
        }

        .invoice-overview__context-label {
            margin: 0;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: #d4d4d8;
        }

        .invoice-overview__context-text {
            margin: 0.35rem 0 0;
            font-size: 0.92rem;
            color: #fafafa;
        }

        .invoice-overview__metrics,
        .invoice-overview__breakdown,
        .invoice-overview__status {
            display: grid;
            gap: 0.9rem;
        }

        .invoice-overview__metrics {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .invoice-overview__metric,
        .invoice-overview__breakdown-card,
        .invoice-overview__status-card {
            border-radius: 20px;
            padding: 1rem;
        }

        .invoice-overview__metric {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .invoice-overview__metric-label,
        .invoice-overview__small-label {
            margin: 0;
            font-size: 0.76rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        .invoice-overview__metric-label {
            color: #d4d4d8;
        }

        .invoice-overview__metric-value {
            margin: 0.55rem 0 0;
            font-size: 1.65rem;
            line-height: 1.1;
            font-weight: 700;
        }

        .invoice-overview__metric-text,
        .invoice-overview__small-text {
            margin: 0.35rem 0 0;
            font-size: 0.92rem;
            line-height: 1.4;
        }

        .invoice-overview__metric-text {
            color: #a1a1aa;
        }

        .invoice-overview__breakdown {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .invoice-overview__breakdown-card {
            background: rgba(0, 0, 0, 0.16);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .invoice-overview__row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .invoice-overview__row-value {
            font-size: 0.95rem;
            font-weight: 600;
            color: #fafafa;
        }

        .invoice-overview__small-label {
            color: #d4d4d8;
        }

        .invoice-overview__small-text {
            color: #a1a1aa;
        }

        .invoice-overview__bar {
            margin-top: 0.8rem;
            height: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
        }

        .invoice-overview__bar-fill {
            height: 100%;
            border-radius: 999px;
        }

        .invoice-overview__bar-fill--services {
            background: linear-gradient(90deg, #22d3ee 0%, #38bdf8 52%, #818cf8 100%);
        }

        .invoice-overview__bar-fill--products {
            background: linear-gradient(90deg, #f472b6 0%, #a78bfa 52%, #8b5cf6 100%);
        }

        .invoice-overview__status-card {
            border: 1px solid #e4e4e7;
            background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);
            box-shadow: 0 20px 60px -40px rgba(15, 23, 42, 0.65);
        }

        .invoice-overview__status-card--amber {
            border-color: rgba(245, 158, 11, 0.25);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.14) 0%, #ffffff 42%, #ffffff 100%);
            color: #78350f;
        }

        .invoice-overview__status-card--emerald {
            border-color: rgba(16, 185, 129, 0.25);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.14) 0%, #ffffff 42%, #ffffff 100%);
            color: #064e3b;
        }

        .invoice-overview__status-card--rose {
            border-color: rgba(244, 63, 94, 0.25);
            background: linear-gradient(135deg, rgba(244, 63, 94, 0.14) 0%, #ffffff 42%, #ffffff 100%);
            color: #881337;
        }

        .invoice-overview__status-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }

        .invoice-overview__status-label {
            margin: 0;
            font-size: 0.95rem;
            font-weight: 600;
            opacity: 0.88;
        }

        .invoice-overview__status-value {
            margin: 0.65rem 0 0;
            font-size: 2rem;
            line-height: 1.05;
            font-weight: 700;
            letter-spacing: -0.04em;
        }

        .invoice-overview__pill {
            display: inline-flex;
            padding: 0.45rem 0.8rem;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
            background: rgba(255, 255, 255, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.7);
        }

        @media (min-width: 1536px) {
            .invoice-overview {
                grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
            }
        }

        @media (max-width: 1024px) {
            .invoice-overview__metrics,
            .invoice-overview__breakdown,
            .invoice-overview__status {
                grid-template-columns: 1fr;
            }
        }

        @media (min-width: 768px) and (max-width: 1535px) {
            .invoice-overview__status {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .invoice-overview__top,
            .invoice-overview__status-top {
                flex-direction: column;
                align-items: flex-start;
            }

            .invoice-overview__context {
                width: 100%;
                text-align: left;
            }

            .invoice-overview__pill {
                white-space: normal;
            }
        }
    </style>

    <div class="invoice-overview">
        <section class="invoice-overview__hero">
            <div class="invoice-overview__hero-content">
                <div class="invoice-overview__top">
                    <div>
                        <span class="invoice-overview__eyebrow">Panorama Financeiro</span>
                        <p class="invoice-overview__subtitle">Valor liquido das faturas filtradas</p>
                        <h2 class="invoice-overview__amount">{{ $summary['net_value'] }}</h2>
                    </div>

                    <div class="invoice-overview__context">
                        <p class="invoice-overview__context-label">Base atual</p>
                        <p class="invoice-overview__context-text">Filtros da tabela</p>
                    </div>
                </div>

                <div class="invoice-overview__metrics">
                    <div class="invoice-overview__metric">
                        <p class="invoice-overview__metric-label">Faturas</p>
                        <p class="invoice-overview__metric-value">{{ number_format($summary['total_invoices'], 0, ',', '.') }}</p>
                        <p class="invoice-overview__metric-text">registros na visao atual</p>
                    </div>

                    <div class="invoice-overview__metric">
                        <p class="invoice-overview__metric-label">Ticket medio</p>
                        <p class="invoice-overview__metric-value">{{ $summary['average_ticket'] }}</p>
                        <p class="invoice-overview__metric-text">por fatura filtrada</p>
                    </div>

                    <div class="invoice-overview__metric">
                        <p class="invoice-overview__metric-label">Composicao</p>
                        <p class="invoice-overview__metric-value">{{ $summary['services_share'] }}</p>
                        <p class="invoice-overview__metric-text">servicos no total liquido</p>
                    </div>
                </div>

                <div class="invoice-overview__breakdown">
                    <div class="invoice-overview__breakdown-card">
                        <div class="invoice-overview__row">
                            <span class="invoice-overview__small-label">Servicos</span>
                            <span class="invoice-overview__row-value">{{ $summary['services_total'] }}</span>
                        </div>
                        <div class="invoice-overview__bar">
                            <div class="invoice-overview__bar-fill invoice-overview__bar-fill--services" style="width: {{ $summary['services_share_width'] }}"></div>
                        </div>
                        <p class="invoice-overview__small-text">{{ $summary['services_share'] }} do valor liquido atual.</p>
                    </div>

                    <div class="invoice-overview__breakdown-card">
                        <div class="invoice-overview__row">
                            <span class="invoice-overview__small-label">Produtos</span>
                            <span class="invoice-overview__row-value">{{ $summary['products_total'] }}</span>
                        </div>
                        <div class="invoice-overview__bar">
                            <div class="invoice-overview__bar-fill invoice-overview__bar-fill--products" style="width: {{ $summary['products_share_width'] }}"></div>
                        </div>
                        <p class="invoice-overview__small-text">{{ $summary['products_share'] }} do valor liquido atual.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="invoice-overview__status">
            @foreach ($statusCards as $card)
                <article class="invoice-overview__status-card invoice-overview__status-card--{{ $card['color'] }}">
                    <div class="invoice-overview__status-top">
                        <div>
                            <p class="invoice-overview__status-label">{{ $card['label'] }}</p>
                            <p class="invoice-overview__status-value">{{ number_format($card['value'], 0, ',', '.') }}</p>
                        </div>

                        <span class="invoice-overview__pill">
                            {{ $card['description'] }}
                        </span>
                    </div>
                </article>
            @endforeach
        </section>
    </div>
</x-filament-widgets::widget>
