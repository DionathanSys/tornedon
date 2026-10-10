@php
    $tenant = filament()->getTenant();
    $user = filament()->auth()->user();
    $panelLinks = collect([
        ['id' => 'admin', 'label' => 'Administração', 'icon' => 'o-building-office-2'],
        ['id' => 'mobile', 'label' => 'Mobile', 'icon' => 'o-device-phone-mobile'],
        ['id' => 'shop', 'label' => 'Shop', 'icon' => 'o-shopping-cart'],
        ['id' => 'management', 'label' => 'Gestão', 'icon' => 'o-cog-6-tooth'],
    ])->map(function ($item) use ($tenant, $user) {
        $panel = filament()->getPanel($item['id'], isStrict: false);
        return $panel && $user->canAccessPanel($panel) ? [...$item, 'url' => $panel->getUrl($panel->hasTenancy() ? $tenant : null)] : null;
    })->filter();
    $companies = collect($this->availableTenants())->map(fn ($company) => ['id' => $company->getKey(), 'name' => filament()->getTenantName($company)])->all();
@endphp

<div>
    <x-mary-dropdown no-x-anchor top right>
        <x-slot:trigger class="operation-bar-button btn btn-ghost">
            <x-mary-icon name="o-bars-3" class="h-5 w-5" />
            <span>Menu</span>
        </x-slot:trigger>
        <x-mary-menu-item title="Mudar empresa" icon="o-arrows-right-left" wire:click="openTenantModal" />
        <x-mary-menu-item title="Nova ordem" icon="o-plus" @click="$dispatch('operation-create-record', { kind: 'service-order' })" />
        <x-mary-menu-item title="Nova requisição" icon="o-plus" @click="$dispatch('operation-create-record', { kind: 'requisition' })" />
        <x-mary-menu-separator />
        <x-mary-menu-item title="Tema claro" icon="o-sun" @click="$dispatch('theme-changed', 'light')" />
        <x-mary-menu-item title="Tema escuro" icon="o-moon" @click="$dispatch('theme-changed', 'dark')" />
        <x-mary-menu-item title="Tema do sistema" icon="o-computer-desktop" @click="$dispatch('theme-changed', 'system')" />
        @if ($panelLinks->isNotEmpty())
            <x-mary-menu-separator />
            @foreach ($panelLinks as $panelLink)
                <x-mary-menu-item :title="$panelLink['label']" :icon="$panelLink['icon']" :link="$panelLink['url']" no-wire-navigate />
            @endforeach
        @endif
    </x-mary-dropdown>

    @teleport('body')
        <x-operation.theme>
            <x-mary-modal wire:model="showTenantModal" title="Mudar empresa" subtitle="Escolha a empresa em que deseja operar." class="backdrop-blur-sm">
                <x-mary-form wire:submit="switchTenant" no-separator>
                    <x-mary-select label="Empresa" wire:model="tenantId" :options="$companies" />
                    <x-slot:actions>
                        <x-mary-button label="Voltar" @click="$wire.showTenantModal = false" />
                        <x-mary-button label="Mudar empresa" class="btn-primary" type="submit" spinner="switchTenant" />
                    </x-slot:actions>
                </x-mary-form>
            </x-mary-modal>
        </x-operation.theme>
    @endteleport
</div>
