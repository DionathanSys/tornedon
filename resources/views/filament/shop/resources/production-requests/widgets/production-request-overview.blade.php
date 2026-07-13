<x-filament-widgets::widget>
    <style>
        .shop-production-overview {
            display: grid;
            gap: 1rem;
            grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
            width: 100%;
        }

        .shop-production-overview__primary,
        .shop-production-overview__stat {
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 1.1rem;
            background: #fff;
            box-shadow: 0 16px 40px -32px rgba(15, 23, 42, 0.35);
        }

        .shop-production-overview__primary {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 4rem;
            padding: 0.25rem;
            background: linear-gradient(135deg, #111827 0%, #334155 100%);
        }

        .shop-production-overview__link {
            display: inline-flex;
            width: 100%;
            align-items: center;
            justify-content: center;
            border-radius: 0.95rem;
            padding: 1rem 1.25rem;
            color: #fff;
            font-size: 0.95rem;
            font-weight: 700;
            text-decoration: none;
        }

        .shop-production-overview__stat {
            padding: 0.85rem 1rem;
        }

        .shop-production-overview__label {
            margin: 0;
            color: #64748b;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .shop-production-overview__value {
            margin: 0.35rem 0 0;
            color: #0f172a;
            font-size: 1.45rem;
            font-weight: 800;
            line-height: 1;
        }

        .dark .shop-production-overview__primary,
        .dark .shop-production-overview__stat {
            border-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 16px 40px -32px rgba(0, 0, 0, 0.8);
        }

        .dark .shop-production-overview__stat {
            background: #111827;
        }

        .dark .shop-production-overview__label {
            color: #94a3b8;
        }

        .dark .shop-production-overview__value {
            color: #f8fafc;
        }

        @media (max-width: 640px) {
            .shop-production-overview {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>

    <div class="shop-production-overview">
        <section class="shop-production-overview__primary">
            <a href="{{ $createUrl }}" class="shop-production-overview__link">Novo pedido de producao</a>
        </section>

        <section class="shop-production-overview__stat">
            <p class="shop-production-overview__label">Em aberto</p>
            <p class="shop-production-overview__value">{{ number_format($openCount, 0, ',', '.') }}</p>
        </section>
    </div>
</x-filament-widgets::widget>
