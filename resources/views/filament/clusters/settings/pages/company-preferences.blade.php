<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        
        <x-filament-actions::modals />
        
        <div style="margin-top: 1rem;">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                Salvar Preferências
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
