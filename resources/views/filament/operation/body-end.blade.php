@include('filament.partials.body-end')

<style>
    .op-bottom-nav {
        position: fixed;
        right: 0;
        bottom: 0;
        left: 0;
        z-index: 50;
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.25rem;
        padding: 0.5rem max(0.75rem, env(safe-area-inset-left)) calc(0.5rem + env(safe-area-inset-bottom)) max(0.75rem, env(safe-area-inset-right));
        border-top: 1px solid rgba(226, 232, 240, 0.9);
        background: rgba(255, 255, 255, 0.94);
        box-shadow: 0 -18px 45px -32px rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(16px);
    }

    .op-bottom-nav__item {
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
        transition: background 0.15s, color 0.15s;
    }

    .op-bottom-nav__item svg {
        width: 1.25rem;
        height: 1.25rem;
        stroke-width: 2.2;
    }

    .op-bottom-nav__item[aria-current='page'] {
        background: #18181b;
        color: #fff;
    }

    .op-bottom-nav__menu,
    .op-menu,
    .op-menu .fi-dropdown,
    .op-menu .fi-dropdown-trigger {
        min-width: 0;
        height: 100%;
    }

    .op-menu__trigger {
        display: flex;
        width: 100%;
        height: 100%;
        min-width: 0;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 0.2rem;
        border: 0;
        border-radius: 0.95rem;
        padding: 0.55rem 0.25rem;
        background: transparent;
        color: #64748b;
        font: inherit;
        font-size: 0.68rem;
        font-weight: 700;
        line-height: 1;
        cursor: pointer;
    }

    .op-menu__trigger:hover,
    .op-menu__trigger:focus-visible {
        background: #f1f5f9;
        color: #18181b;
        outline: none;
    }

    .op-menu__trigger svg {
        width: 1.25rem;
        height: 1.25rem;
        stroke-width: 2.2;
    }

    .op-bottom-nav__badge {
        position: absolute;
        top: -2px;
        right: -2px;
        min-width: 1rem;
        height: 1rem;
        border-radius: 9999px;
        background: #ef4444;
        color: #fff;
        font-size: 0.55rem;
        font-weight: 800;
        line-height: 1rem;
        text-align: center;
        padding: 0 0.2rem;
    }

    @media (min-width: 768px) {
        .op-bottom-nav {
            right: 1rem;
            bottom: 1rem;
            left: 1rem;
            max-width: 28rem;
            margin-inline: auto;
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 1.35rem;
            padding: 0.5rem;
        }
    }
</style>

<nav class="op-bottom-nav" aria-label="Navegação principal">
    @php
        $currentPath = '/' . trim(request()->path(), '/');
        $hideNavigation = str_ends_with($currentPath, '/create') || str_ends_with($currentPath, '/edit');
    @endphp

    @if (! $hideNavigation)
        @php
            $tenant = \Filament\Facades\Filament::getTenant();
            $items = [
                [
                    'label' => 'Início',
                    'url' => \App\Filament\Operation\Pages\OperationDashboard::getUrl(tenant: $tenant),
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="m3 10.5 9-7 9 7"/><path stroke-linecap="round" stroke-linejoin="round" d="M5 9.5V20h5v-6h4v6h5V9.5"/>',
                    'slug' => 'dashboard',
                ],
                [
                    'label' => 'Ordens',
                    'url' => \App\Filament\Operation\Pages\ServiceOrders\ServiceOrderQueue::getUrl(tenant: $tenant),
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5h6"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 4h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2V5a1 1 0 0 1 1-1Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h8M8 16h5"/>',
                    'slug' => 'ordens',
                ],
                [
                    'label' => 'Requisições',
                    'url' => \App\Filament\Operation\Pages\Requisitions\RequisitionList::getUrl(tenant: $tenant),
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2h-2a2 2 0 0 1-2-2Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6M9 16h6"/>',
                    'slug' => 'requisicoes',
                ],
            ];
        @endphp

        @foreach ($items as $item)
            @php
                $isActive = str_contains($currentPath, $item['slug']);
            @endphp

            <a
                href="{{ $item['url'] }}"
                class="op-bottom-nav__item"
                @if ($isActive) aria-current="page" @endif
                wire:navigate
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">{!! $item['icon'] !!}</svg>
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach

        <div class="op-bottom-nav__menu">
            @livewire(\App\Livewire\OperationMenu::class)
        </div>
    @endif
</nav>

<script>
    (() => {
        const selectors = [
            'input:not([type="hidden"]):not([disabled])',
            'textarea:not([disabled])',
            'select:not([disabled])',
            '[contenteditable="true"]',
        ].join(',');

        if (! window.visualViewport) {
            return;
        }

        let activeField = null;
        let scrollTimer = null;

        const scheduleScroll = () => {
            if (! activeField || ! document.contains(activeField)) {
                return;
            }

            window.clearTimeout(scrollTimer);

            scrollTimer = window.setTimeout(() => {
                if (! activeField || ! document.contains(activeField)) {
                    return;
                }

                const viewportHeight = window.visualViewport.height;

                if (viewportHeight >= window.innerHeight - 80) {
                    return;
                }

                const rect = activeField.getBoundingClientRect();
                const padding = 24;
                const visibleBottom = viewportHeight - padding;

                if (rect.bottom <= visibleBottom && rect.top >= padding) {
                    return;
                }

                const modalContent = activeField.closest('.fi-modal-content');

                if (modalContent) {
                    const delta = rect.bottom > visibleBottom
                        ? rect.bottom - visibleBottom + 16
                        : rect.top - padding - 16;

                    modalContent.scrollBy({
                        top: delta,
                        behavior: 'smooth',
                    });

                    return;
                }

                activeField.scrollIntoView({
                    block: 'nearest',
                    inline: 'nearest',
                    behavior: 'smooth',
                });
            }, 180);
        };

        document.addEventListener('focusin', (event) => {
            const target = event.target;

            if (! (target instanceof HTMLElement) || ! target.matches(selectors)) {
                return;
            }

            activeField = target;
            scheduleScroll();
        });

        document.addEventListener('focusout', (event) => {
            if (event.target === activeField) {
                activeField = null;
            }
        });

        window.visualViewport.addEventListener('resize', scheduleScroll);
        window.visualViewport.addEventListener('scroll', scheduleScroll);
    })();
</script>
