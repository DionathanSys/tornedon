@php
    $currentTenant = filament()->getTenant();
    $tenants = filament()->getUserTenants(filament()->auth()->user());
@endphp

@if ($currentTenant && count($tenants) > 0)
    <style>
        .op-company-switcher { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 1rem; padding: 0.7rem 0.85rem; border: 1px solid rgba(226, 232, 240, 0.9); border-radius: 1rem; background: #fff; }
        .op-company-switcher__context { min-width: 0; }
        .op-company-switcher__label { display: block; color: #64748b; font-size: 0.62rem; font-weight: 800; letter-spacing: 0.04em; text-transform: uppercase; }
        .op-company-switcher__name { display: block; overflow: hidden; margin-top: 0.15rem; color: #0f172a; font-size: 0.82rem; font-weight: 800; text-overflow: ellipsis; white-space: nowrap; }
        .op-company-switcher__trigger { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; min-height: 2.5rem; padding: 0.55rem 0.75rem; border: 0; border-radius: 0.75rem; background: #18181b; color: #fff; font-size: 0.72rem; font-weight: 800; cursor: pointer; }
        .op-company-switcher__trigger svg { width: 1rem; height: 1rem; margin-left: 0.35rem; }
        .op-company-switcher__option--current { color: #2563eb; font-weight: 800; }
    </style>

    <div class="op-company-switcher">
        <div class="op-company-switcher__context">
            <span class="op-company-switcher__label">Empresa atual</span>
            <span class="op-company-switcher__name">{{ filament()->getTenantName($currentTenant) }}</span>
        </div>

        <x-filament::dropdown placement="bottom-end" size>
            <x-slot name="trigger">
                <button type="button" class="op-company-switcher__trigger">
                    Trocar
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </x-slot>

            <x-filament::dropdown.list>
                @foreach ($tenants as $tenant)
                    <x-filament::dropdown.list.item
                        :href="filament()->getUrl($tenant)"
                        :image="filament()->getTenantAvatarUrl($tenant)"
                        tag="a"
                        @class(['op-company-switcher__option--current' => $tenant->is($currentTenant)])
                    >
                        {{ filament()->getTenantName($tenant) }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        </x-filament::dropdown>
    </div>
@endif
