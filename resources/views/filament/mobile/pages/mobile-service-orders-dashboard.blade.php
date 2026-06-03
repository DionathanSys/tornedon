<x-filament-panels::page>
    <style>
        .mobile-os-dashboard-card {
            border: 1px solid rgb(228 228 231);
            background: rgb(255 255 255);
            color: rgb(24 24 27);
        }

        .mobile-os-dashboard-muted {
            color: rgb(113 113 122);
        }

        .dark .mobile-os-dashboard-card {
            border-color: rgba(255, 255, 255, 0.10);
            background: rgba(255, 255, 255, 0.05);
            color: rgb(255 255 255);
        }

        .dark .mobile-os-dashboard-muted {
            color: rgb(161 161 170);
        }

        .dark .mobile-os-dashboard-input {
            border-color: rgba(255, 255, 255, 0.10);
            background: rgba(255, 255, 255, 0.05);
            color: rgb(255 255 255);
        }
    </style>

    <div class="space-y-4">
        <section class="mobile-os-dashboard-card rounded-2xl p-4 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold">Data de referência</h2>
                </div>

                <label class="block">
                    <input
                        type="date"
                        wire:model.live="selectedDate"
                        class="mobile-os-dashboard-input w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm focus:border-zinc-400 focus:outline-none focus:ring-2 focus:ring-zinc-200 sm:w-52"
                    >
                </label>
            </div>

            <p class="mobile-os-dashboard-muted mt-3 text-sm">Exibindo resultados para <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->getSelectedDateLabel() }}</span>.</p>
        </section>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach ($this->stats as $stat)
                <section class="mobile-os-dashboard-card rounded-2xl p-4 shadow-sm">
                    <p class="mobile-os-dashboard-muted text-sm font-medium">{{ $stat['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold {{ $stat['color'] }}">{{ $stat['value'] }}</p>
                    <p class="mobile-os-dashboard-muted mt-1 text-xs">{{ $stat['description'] }}</p>
                </section>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
