<x-filament-widgets::widget>
    <style>
        .shop-production-overview {
            width: 100%;
        }

        .shop-production-overview__primary {
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 1.1rem;
            box-shadow: 0 16px 40px -32px rgba(15, 23, 42, 0.35);
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

        .dark .shop-production-overview__primary {
            border-color: rgba(255, 255, 255, 0.08);
            box-shadow: 0 16px 40px -32px rgba(0, 0, 0, 0.8);
        }
    </style>

    <div class="shop-production-overview">
        <section class="shop-production-overview__primary">
            <a href="{{ $createUrl }}" class="shop-production-overview__link">Novo pedido de producao</a>
        </section>
    </div>
</x-filament-widgets::widget>
