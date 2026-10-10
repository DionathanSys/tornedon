<div class="space-y-3">
    <div class="flex flex-wrap items-center gap-3">
        <x-mary-button label="Filtrar" icon="o-funnel" wire:click="$toggle('showFilters')" :aria-expanded="$showFilters ? 'true' : 'false'" :class="$dateFrom || $dateUntil ? 'btn-outline btn-primary btn-sm' : 'btn-ghost btn-sm'" />
        @if ($label = $this->appliedDateLabel())
            <span class="text-xs text-base-content/70">Período: {{ $label }}</span>
            @if (! $showFilters)
                <x-mary-button label="Limpar" icon="o-x-mark" class="btn-ghost btn-xs" wire:click="clearDateFilters" spinner="clearDateFilters" />
            @endif
        @endif
    </div>

    @if ($showFilters)
        <x-mary-form wire:submit="applyDateFilters" no-separator class="rounded-box border border-base-300 bg-base-200 p-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-mary-input label="Data inicial" type="date" wire:model="dateFilter.from" />
                <x-mary-input label="Data final" type="date" wire:model="dateFilter.until" />
            </div>
            <x-slot:actions>
                <x-mary-button label="Limpar" class="btn-ghost btn-sm" wire:click="clearDateFilters" spinner="clearDateFilters" />
                <x-mary-button label="Aplicar" icon="o-check" class="btn-primary btn-sm" type="submit" spinner="applyDateFilters" />
            </x-slot:actions>
        </x-mary-form>
    @endif
</div>
