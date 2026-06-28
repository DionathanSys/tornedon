<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <x-filament-actions::modals />

        <div style="margin-top: 1rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                Salvar Configurações
            </x-filament::button>

            <x-filament::button type="button" color="info" wire:click="consult" wire:loading.attr="disabled">
                Consultar CNPJ
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
