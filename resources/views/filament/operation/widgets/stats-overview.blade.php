<x-filament-widgets::widget>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" wire:poll.30s>
        @foreach ($this->getSummaryStats() as $stat)
            <x-operation.stat :title="$stat->getLabel()" :value="$stat->getValue()" :description="$stat->getDescription()" />
        @endforeach
    </div>
</x-filament-widgets::widget>
