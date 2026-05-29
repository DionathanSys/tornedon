<x-filament-widgets::widget>
    <style>
        .invoice-overview {
            display: grid;
            gap: 0.85rem;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            width: 100%;
        }

        .invoice-overview__card {
            position: relative;
            overflow: hidden;
            border-radius: 18px;
            padding: 1rem;
            border: 1px solid #e4e4e7;
            background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);
            box-shadow: 0 18px 42px -34px rgba(15, 23, 42, 0.35);
        }

        .invoice-overview__card--neutral {
            color: #18181b;
        }

        .invoice-overview__card--amber {
            border-color: rgba(245, 158, 11, 0.25);
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.14) 0%, #ffffff 42%, #ffffff 100%);
            color: #78350f;
        }

        .invoice-overview__card--success {
            border-color: rgba(16, 185, 129, 0.25);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.14) 0%, #ffffff 42%, #ffffff 100%);
            color: #064e3b;
        }

        .invoice-overview__card--danger {
            border-color: rgba(244, 63, 94, 0.25);
            background: linear-gradient(135deg, rgba(244, 63, 94, 0.14) 0%, #ffffff 42%, #ffffff 100%);
            color: #881337;
        }

        .invoice-overview__content {
            display: grid;
            gap: 0.85rem;
        }

        .invoice-overview__label {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: inherit;
            opacity: 0.8;
        }

        .invoice-overview__icon {
            width: 1rem;
            height: 1rem;
            flex-shrink: 0;
            opacity: 0.9;
        }

        .invoice-overview__value {
            margin: 0;
            font-size: clamp(1.35rem, 1.7vw, 1.95rem);
            line-height: 1.05;
            font-weight: 700;
            letter-spacing: -0.04em;
            text-wrap: balance;
        }

        .invoice-overview__footer {
            padding-top: 0.8rem;
            border-top: 1px solid rgba(39, 39, 42, 0.08);
        }

        .invoice-overview__footer-label {
            margin: 0;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            opacity: 0.68;
        }

        .invoice-overview__footer-value {
            margin: 0.3rem 0 0;
            font-size: 0.92rem;
            font-weight: 600;
            color: inherit;
        }

        .dark .invoice-overview__card {
            border-color: rgba(255, 255, 255, 0.08);
            background: linear-gradient(135deg, #18181b 0%, #111827 100%);
            color: #fafafa;
            box-shadow: 0 18px 42px -34px rgba(0, 0, 0, 0.8);
        }

        .dark .invoice-overview__card--amber {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.18) 0%, #111827 58%, #111827 100%);
            color: #fef3c7;
        }

        .dark .invoice-overview__card--success {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.18) 0%, #111827 58%, #111827 100%);
            color: #d1fae5;
        }

        .dark .invoice-overview__card--danger {
            background: linear-gradient(135deg, rgba(244, 63, 94, 0.18) 0%, #111827 58%, #111827 100%);
            color: #ffe4e6;
        }

        .dark .invoice-overview__footer {
            border-top-color: rgba(255, 255, 255, 0.1);
        }

        @media (max-width: 1280px) {
            .invoice-overview {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 768px) {
            .invoice-overview {
                display: none;
            }
        }
    </style>

    <div class="invoice-overview">
        @foreach ($cards as $card)
            <article class="invoice-overview__card invoice-overview__card--{{ $card['variant'] }}">
                <div class="invoice-overview__content">
                    <p class="invoice-overview__label">
                        <x-filament::icon :icon="$card['icon']" class="invoice-overview__icon" />
                        {{ $card['label'] }}
                    </p>

                    <p class="invoice-overview__value">{{ $card['value'] }}</p>

                    <div class="invoice-overview__footer">
                        <p class="invoice-overview__footer-label">{{ $card['footer_label'] }}</p>
                        <p class="invoice-overview__footer-value">{{ $card['footer_value'] }}</p>
                    </div>
                </div>
            </article>
        @endforeach
    </div>
</x-filament-widgets::widget>
