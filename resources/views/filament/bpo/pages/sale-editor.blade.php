<x-filament-panels::page class="bpo-workbench-page">
    <form wire:submit="finalizeSale" class="space-y-6">
        {{ $this->form }}

        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-filament::button color="gray" wire:click="saveDraft" wire:loading.attr="disabled" type="button">
                Salvar rascunho
            </x-filament::button>
            @if (app(\App\Services\Access\UserAccessService::class)->allows(filament()->auth()->user(), filament()->getTenant(), 'bpo_sales.approve', 'bpo'))
                <x-filament::button type="submit" wire:loading.attr="disabled">
                    Finalizar venda
                </x-filament::button>
            @endif
        </div>
    </form>
</x-filament-panels::page>
