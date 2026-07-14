@php
    use App\Filament\Shop\Pages\ShopDashboard;
    use App\Filament\Shop\Resources\AccountPayables\AccountPayableResource;
    use App\Filament\Shop\Resources\AccountReceivables\AccountReceivableResource;
    use App\Filament\Shop\Resources\CashMovements\CashMovementResource;
    use App\Filament\Shop\Resources\ProductionRequests\ProductionRequestResource;
    use Filament\Facades\Filament;

    $tenant = Filament::getTenant();
@endphp

@if (Filament::auth()->check() && $tenant)
    @php
        $items = [
            [
                'label' => 'Inicio',
                'url' => ShopDashboard::getUrl(),
                'icon' => 'home',
            ],
            [
                'label' => 'Producao',
                'url' => ProductionRequestResource::getUrl(),
                'icon' => 'clipboard',
            ],
            [
                'label' => 'Receber',
                'url' => AccountReceivableResource::getUrl(),
                'icon' => 'up',
            ],
            [
                'label' => 'Pagar',
                'url' => AccountPayableResource::getUrl(),
                'icon' => 'down',
            ],
            [
                'label' => 'Caixa',
                'url' => CashMovementResource::getUrl(),
                'icon' => 'swap',
            ],
        ];

        $currentPath = '/' . trim(request()->path(), '/');
    @endphp

    <style>
        .fi-main {
            padding-bottom: calc(5.5rem + env(safe-area-inset-bottom));
        }

        .shop-bottom-navigation {
            position: fixed;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 50;
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0.25rem;
            padding: 0.5rem max(0.75rem, env(safe-area-inset-left)) calc(0.5rem + env(safe-area-inset-bottom)) max(0.75rem, env(safe-area-inset-right));
            border-top: 1px solid rgba(226, 232, 240, 0.9);
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 -18px 45px -32px rgba(15, 23, 42, 0.7);
            backdrop-filter: blur(16px);
        }

        .shop-bottom-navigation__item {
            display: flex;
            min-width: 0;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.2rem;
            border-radius: 0.95rem;
            padding: 0.55rem 0.25rem;
            color: #64748b;
            font-size: 0.68rem;
            font-weight: 700;
            line-height: 1;
            text-decoration: none;
        }

        .shop-bottom-navigation__item svg {
            width: 1.25rem;
            height: 1.25rem;
            stroke-width: 2.2;
        }

        .shop-bottom-navigation__item[aria-current='page'] {
            background: #18181b;
            color: #fff;
        }

        @media (min-width: 768px) {
            .shop-bottom-navigation {
                right: 1rem;
                bottom: 1rem;
                left: 1rem;
                max-width: 44rem;
                margin-inline: auto;
                border: 1px solid rgba(226, 232, 240, 0.9);
                border-radius: 1.35rem;
                padding: 0.5rem;
            }
        }
    </style>

    <nav class="shop-bottom-navigation" aria-label="Navegacao principal do shop">
        @foreach ($items as $item)
            @php
                $itemPath = '/' . trim(parse_url($item['url'], PHP_URL_PATH) ?: '', '/');
                $isActive = $itemPath === $currentPath || ($itemPath !== '/' && str_starts_with($currentPath, $itemPath . '/'));
            @endphp

            <a
                href="{{ $item['url'] }}"
                class="shop-bottom-navigation__item"
                @if ($isActive) aria-current="page" @endif
                wire:navigate
            >
                @switch($item['icon'])
                    @case('home')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m3 10.5 9-7 9 7"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 9.5V20h5v-6h4v6h5V9.5"/></svg>
                        @break

                    @case('clipboard')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5h6"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 4h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2V5a1 1 0 0 1 1-1Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h8M8 16h5"/></svg>
                        @break

                    @case('up')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 17 14 7"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h6v6"/><path stroke-linecap="round" stroke-linejoin="round" d="M20 19H4"/></svg>
                        @break

                    @case('down')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7 14 17"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 11v6H8"/><path stroke-linecap="round" stroke-linejoin="round" d="M20 19H4"/></svg>
                        @break

                    @case('swap')
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h11l-3-3"/><path stroke-linecap="round" stroke-linejoin="round" d="M17 17H6l3 3"/><path stroke-linecap="round" stroke-linejoin="round" d="M18 7l-3 3M6 17l3-3"/></svg>
                        @break
                @endswitch

                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </nav>
@endif
