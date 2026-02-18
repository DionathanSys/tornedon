<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        
        <div class="mt-6 m-2">
            <x-filament::button type="submit" wire:loading.attr="disabled">
                Salvar Preferências
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
