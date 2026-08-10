<x-filament-panels::page>
    @php($rows = $this->rows)
    @php($companies = $this->selectedCompanies())

    <div class="space-y-6">
        <form wire:submit="applyFilters" class="space-y-4">
            {{ $this->form }}

            <x-filament::button type="submit" wire:loading.attr="disabled">
                Aplicar filtros
            </x-filament::button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Linha</th>
                        @foreach($companies as $company)
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $company->name }}</th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Consolidado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @forelse($rows as $row)
                        <tr @class(['bg-gray-50/70 dark:bg-white/5' => $row['is_bold']])>
                            <td @class(['px-4 py-3 text-sm text-gray-900 dark:text-white', 'font-semibold' => $row['is_bold'], 'font-medium' => ! $row['is_bold']])>
                                <span style="padding-left: {{ (int) $row['depth'] * 1.25 }}rem">
                                @if($row['code'])
                                    <span class="text-gray-500 dark:text-gray-400">{{ $row['code'] }}</span>
                                @endif
                                {{ $row['name'] }}
                                </span>
                            </td>
                            @foreach($companies as $company)
                                <td class="px-4 py-3 text-right text-sm text-gray-700 dark:text-gray-300">
                                    {{ $this->money((float) data_get($row, 'amounts.' . $company->id, 0)) }}
                                </td>
                            @endforeach
                            <td class="px-4 py-3 text-right text-sm font-semibold text-gray-950 dark:text-white">
                                {{ $this->money((float) $row['total']) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $companies->count() + 2 }}" class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                                Nenhum resultado encontrado para os filtros informados.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
