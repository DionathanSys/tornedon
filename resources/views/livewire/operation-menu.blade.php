@php
    $currentTenant = filament()->getTenant();
    $user = filament()->auth()->user();
    $panelLinks = collect([
        ['id' => 'admin', 'label' => 'Administração', 'icon' => \Filament\Support\Icons\Heroicon::BuildingOffice2],
        ['id' => 'mobile', 'label' => 'Mobile', 'icon' => \Filament\Support\Icons\Heroicon::DevicePhoneMobile],
        ['id' => 'shop', 'label' => 'Shop', 'icon' => \Filament\Support\Icons\Heroicon::ShoppingCart],
        ['id' => 'management', 'label' => 'Gestão', 'icon' => \Filament\Support\Icons\Heroicon::Cog6Tooth],
    ])->map(function (array $item) use ($currentTenant, $user): ?array {
        $panel = filament()->getPanel($item['id'], isStrict: false);

        if (! $panel || $panel->getId() === filament()->getId() || ! $user->canAccessPanel($panel)) {
            return null;
        }

        $url = $panel->getUrl($panel->hasTenancy() ? $currentTenant : null);

        return $url ? [...$item, 'url' => $url] : null;
    })->filter()->values();
@endphp

<div class="op-menu">
    <x-filament::dropdown placement="top-end" teleport>
        <x-slot name="trigger">
            <button
                type="button"
                class="op-menu__trigger"
                aria-label="Abrir menu de operação"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <span>Menu</span>
            </button>
        </x-slot>

        <x-filament::dropdown.header :icon="\Filament\Support\Icons\Heroicon::OutlinedEllipsisHorizontalCircle">
            Operação
        </x-filament::dropdown.header>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                tag="button"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedArrowsRightLeft"
                x-on:click="close()"
                wire:click="mountAction('switchTenant')"
            >
                Mudar empresa
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item
                tag="button"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedPlus"
                x-on:click="close()"
                wire:click="mountAction('createServiceOrder')"
            >
                Nova ordem
            </x-filament::dropdown.list.item>
            <x-filament::dropdown.list.item
                tag="button"
                :icon="\Filament\Support\Icons\Heroicon::OutlinedPlus"
                x-on:click="close()"
                wire:click="mountAction('createRequisition')"
            >
                Nova requisição
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>

        @if ($panelLinks->isNotEmpty())
            <x-filament::dropdown.header :icon="\Filament\Support\Icons\Heroicon::OutlinedSquares2x2">
                Outros painéis
            </x-filament::dropdown.header>

            <x-filament::dropdown.list>
                @foreach ($panelLinks as $panelLink)
                    <x-filament::dropdown.list.item
                        :href="$panelLink['url']"
                        :icon="$panelLink['icon']"
                        tag="a"
                    >
                        {{ $panelLink['label'] }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        @endif
    </x-filament::dropdown>

    <x-filament-actions::modals />
</div>
